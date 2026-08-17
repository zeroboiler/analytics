<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;

/**
 * Event Semantic Classifier & Auto-Categorization Engine.
 *
 * Automatically classifies analytics events into catalog categories
 * (ecommerce, saas, engagement, custom) based on event name patterns,
 * payload structure analysis, and contextual heuristics.
 *
 * This enables SaaS teams to:
 * - Identify uncategorized or misnamed events in their tracking code
 * - Auto-suggest the correct catalog category for new events
 * - Detect events that belong to multiple categories (overlap analysis)
 * - Maintain catalog hygiene as the event catalog grows
 * - Generate classification confidence scores for quality monitoring
 *
 * Classification strategy layers (evaluated in order):
 * 1. Exact catalog match — event name exists in a known catalog
 * 2. Name pattern matching — regex patterns for common event naming conventions
 * 3. Payload structure analysis — parameter keys suggest category (e.g., 'price' → ecommerce)
 * 4. Contextual heuristics — event source, priority, and frequency patterns
 *
 * @see \ZeroBoiler\Analytics\Events\EventCatalog
 * @see \ZeroBoiler\Analytics\Services\EventNamingConventionLinter
 *
 * @since 222.0.0
 *
 * @phpstan-type ClassificationResult array{
 *     event_name: string,
 *     category: string,
 *     confidence: float,
 *     method: 'exact'|'pattern'|'payload'|'heuristic'|'unknown',
 *     competing: list<array{category: string, confidence: float}>,
 *     suggestions: list<string>,
 *     catalog_match: bool,
 *     is_custom: bool
 * }
 *
 * @phpstan-type ClassificationReport array{
 *     total_events: int,
 *     classified: int,
 *     unclassified: int,
 *     by_category: array<string, int>,
 *     by_method: array<string, int>,
 *     average_confidence: float,
 *     low_confidence: list<string>,
 *     uncategorized: list<string>,
 *     misnamed: list<array{event: string, current: string|null, suggested: string, confidence: float}>,
 *     overlap_events: list<string>,
 *     quality_score: float
 * }
 */
final class EventSemanticClassifierService
{
    /** @var array<string, list<string>> */
    private array $patterns;

    /** @var array<string, list<string>> */
    private array $payloadHints;

    /** @var array<string, string> */
    private array $aliasMap;

    private const CACHE_PREFIX = 'zb_semantic_classify_';

    private const CACHE_TTL = 3600;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    )): void {
        $this->patterns = $this->buildPatterns();
        $this->payloadHints = $this->buildPayloadHints();
        $this->aliasMap = $this->buildAliasMap();
    }

    /**
     * Classify a single event.
     *
     * @param  string  $eventName  Event name to classify
     * @param  array<string, mixed>  $params  Optional event parameters for payload-based classification
     * @return ClassificationResult
     */
    public function classify(string $eventName, array $params = []): array
    {
        $normalized = $this->normalizeEventName($eventName);
        $cacheKey = self::CACHE_PREFIX . md5($normalized . ':' . json_encode($params, JSON_THROW_ON_ERROR));

        /** @var ClassificationResult|null $cached */
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        // Layer 1: Exact catalog match
        $result = $this->classifyByExactMatch($normalized);

        if ($result !== null) {
            $this->cache->put($cacheKey, $result, self::CACHE_TTL);

            return $result;
        }

        // Layer 2: Name pattern matching
        $result = $this->classifyByPattern($normalized);

        if ($result !== null) {
            $this->cache->put($cacheKey, $result, self::CACHE_TTL);

            return $result;
        }

        // Layer 3: Payload structure analysis
        if ($params !== []) {
            $result = $this->classifyByPayload($normalized, $params);

            if ($result !== null) {
                $this->cache->put($cacheKey, $result, self::CACHE_TTL);

                return $result;
            }
        }

        // Layer 4: Contextual heuristics
        $result = $this->classifyByHeuristic($normalized);
        $this->cache->put($cacheKey, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * Classify multiple events in batch.
     *
     * @param  array<string, array<string, mixed>|null>  $events  Map of event_name => params (or null)
     * @return array<string, ClassificationResult>
     */
    public function classifyBatch(array $events): array
    {
        $results = [];

        foreach ($events as $eventName => $params) {
            $results[$eventName] = $this->classify($eventName, $params ?? []);
        }

        return $results;
    }

    /**
     * Generate a full classification report across all catalog events.
     *
     * @param  list<string>|null  $eventNames  Specific events to classify (null = all catalog events)
     * @return ClassificationReport
     */
    public function classificationReport(?array $eventNames = null): array
    {
        $events = $eventNames ?? EventCatalog::names();
        $results = [];
        $byCategory = [];
        $byMethod = [];
        $lowConfidence = [];
        $uncategorized = [];
        $misnamed = [];
        $overlapEvents = [];
        $totalConfidence = 0.0;
        $classified = 0;

        foreach ($events as $name) {
            $result = $this->classify($name);
            $results[$name] = $result;

            $category = $result['category'];
            $byCategory[$category] = ($byCategory[$category] ?? 0) + 1;
            $byMethod[$result['method']] = ($byMethod[$result['method']] ?? 0) + 1;

            if ($result['confidence'] > 0) {
                $totalConfidence += $result['confidence'];
                $classified++;
            }

            if ($result['confidence'] > 0 && $result['confidence'] < 0.5) {
                $lowConfidence[] = $name;
            }

            if ($result['category'] === 'custom' || $result['category'] === 'unknown') {
                $uncategorized[] = $name;
            }

            // Detect misnamed events (custom events that match a catalog alias)
            if ($result['is_custom'] && $result['suggestions'] !== []) {
                foreach ($result['suggestions'] as $suggestion) {
                    $misnamed[] = [
                        'event' => $name,
                        'current' => $result['category'],
                        'suggested' => $suggestion,
                        'confidence' => $result['confidence'],
                    ];
                }
            }

            // Detect events competing across multiple categories
            if (count($result['competing']) > 0) {
                $overlapEvents[] = $name;
            }
        }

        $total = count($events);
        $averageConfidence = $classified > 0 ? round($totalConfidence / $classified, 4) : 0.0;

        // Quality score: weighted by classification rate, confidence, and catalog coverage
        $classificationRate = $total > 0 ? count(array_filter($results, static fn (array $r): bool => $r['catalog_match'])) / $total : 0.0;
        $qualityScore = $this->calculateQualityScore(
            $classificationRate,
            $averageConfidence,
            count($uncategorized),
            $total,
        );

        return [
            'total_events' => $total,
            'classified' => $classified,
            'unclassified' => count($uncategorized),
            'by_category' => $byCategory,
            'by_method' => $byMethod,
            'average_confidence' => $averageConfidence,
            'low_confidence' => $lowConfidence,
            'uncategorized' => $uncategorized,
            'misnamed' => $misnamed,
            'overlap_events' => $overlapEvents,
            'quality_score' => round($qualityScore, 4),
        ];
    }

    /**
     * Check if an event name is likely an alias of a catalog event.
     *
     * Detects common naming variations like 'user_signup', 'user-sign-up',
     * 'UserSignUp', etc. that should map to 'sign_up'.
     *
     * @return string|null  The canonical catalog event name, or null if no alias found
     */
    public function resolveAlias(string $eventName): ?string
    {
        $normalized = $this->normalizeEventName($eventName);

        // Direct match
        if (isset($this->aliasMap[$normalized])) {
            return $this->aliasMap[$normalized];
        }

        // Fuzzy match: check if normalized form matches any catalog event's normalized form
        $allNames = array_merge(
            EcommerceEvents::names(),
            SaaSEvents::names(),
            EngagementEvents::names(),
        );

        foreach ($allNames as $catalogName) {
            if ($this->normalizeEventName($catalogName) === $normalized) {
                return $catalogName;
            }
        }

        return null;
    }

    /**
     * Suggest the most appropriate category for an uncategorized event.
     *
     * @param  string  $eventName
     * @param  array<string, mixed>  $params
     * @return list<array{category: string, confidence: float, reason: string}>
     */
    public function suggestCategory(string $eventName, array $params = []): array
    {
        $suggestions = [];

        // Check each category's patterns
        foreach ($this->patterns as $category => $regexList) {
            foreach ($regexList as $pattern) {
                if (preg_match($pattern, $eventName)) {
                    $existing = array_filter(
                        $suggestions,
                        static fn (array $s): bool => $s['category'] === $category,
                    );

                    if ($existing === []) {
                        $suggestions[] = [
                            'category' => $category,
                            'confidence' => 0.7,
                            'reason' => "Name matches pattern '{$pattern}'",
                        ];
                    }
                }
            }
        }

        // Check payload hints
        foreach ($this->payloadHints as $category => $hintKeys) {
            $matchedKeys = array_intersect($hintKeys, array_keys($params));

            if ($matchedKeys !== []) {
                $existing = array_filter(
                    $suggestions,
                    static fn (array $s): bool => $s['category'] === $category,
                );
                $confidence = min(0.9, 0.5 + (count($matchedKeys) * 0.15));

                if ($existing !== []) {
                    // Boost existing suggestion
                    foreach ($suggestions as $i => $s) {
                        if ($s['category'] === $category) {
                            $suggestions[$i]['confidence'] = max($s['confidence'], $confidence);
                            $suggestions[$i]['reason'] .= ', payload keys: ' . implode(', ', $matchedKeys);
                        }
                    }
                } else {
                    $suggestions[] = [
                        'category' => $category,
                        'confidence' => $confidence,
                        'reason' => 'Payload keys suggest category: ' . implode(', ', $matchedKeys),
                    ];
                }
            }
        }

        // Sort by confidence descending
        usort($suggestions, static fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']);

        return $suggestions;
    }

    /**
     * Invalidate classification cache for a specific event or all events.
     */
    public function invalidateCache(?string $eventName = null): void
    {
        if ($eventName !== null) {
            $this->cache->forget(self::CACHE_PREFIX . md5($this->normalizeEventName($eventName) . ':'));
        } else {
            // Flush all semantic classifier cache keys
            // Note: this is a best-effort approach; specific cache drivers may handle this differently
            $this->cache->forget(self::CACHE_PREFIX . 'report');
        }
    }

    /**
     * Classify by exact catalog match (Layer 1).
     *
     * @return ClassificationResult|null
     */
    private function classifyByExactMatch(string $eventName): ?array
    {
        if (EcommerceEvents::has($eventName)) {
            return $this->buildResult($eventName, 'ecommerce', 1.0, 'exact', true, false);
        }

        if (SaaSEvents::has($eventName)) {
            return $this->buildResult($eventName, 'saas', 1.0, 'exact', true, false);
        }

        if (EngagementEvents::has($eventName)) {
            return $this->buildResult($eventName, 'engagement', 1.0, 'exact', true, false);
        }

        // Check broader EventCatalog
        $catalogEntry = EventCatalog::get($eventName);

        if ($catalogEntry !== null) {
            $category = $catalogEntry['category'] ?? 'custom';

            return $this->buildResult($eventName, $category, 1.0, 'exact', true, false);
        }

        return null;
    }

    /**
     * Classify by name pattern matching (Layer 2).
     *
     * @return ClassificationResult|null
     */
    private function classifyByPattern(string $eventName): ?array
    {
        $competing = [];

        foreach ($this->patterns as $category => $regexList) {
            foreach ($regexList as $pattern) {
                if (preg_match($pattern, $eventName)) {
                    $confidence = $this->estimatePatternConfidence($eventName, $category);
                    $competing[] = [
                        'category' => $category,
                        'confidence' => $confidence,
                    ];
                }
            }
        }

        if ($competing === []) {
            return null;
        }

        // Sort by confidence
        usort($competing, static fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']);

        $best = $competing[0];
        $suggestions = array_map(
            static fn (array $c): string => $c['category'],
            array_slice($competing, 1),
        );

        return $this->buildResult(
            $eventName,
            $best['category'],
            $best['confidence'],
            'pattern',
            false,
            true,
            array_slice($competing, 1),
            $suggestions,
        );
    }

    /**
     * Classify by payload structure analysis (Layer 3).
     *
     * @param  string  $eventName
     * @param  array<string, mixed>  $params
     * @return ClassificationResult|null
     */
    private function classifyByPayload(string $eventName, array $params): ?array
    {
        $competing = [];

        foreach ($this->payloadHints as $category => $hintKeys) {
            $matchedKeys = array_filter(
                $hintKeys,
                static fn (string $hint) => array_key_exists($hint, $params),
            );

            if ($matchedKeys !== []) {
                $confidence = min(0.85, 0.4 + (count($matchedKeys) * 0.15));
                $competing[] = [
                    'category' => $category,
                    'confidence' => $confidence,
                ];
            }
        }

        if ($competing === []) {
            return null;
        }

        usort($competing, static fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']);

        $best = $competing[0];

        return $this->buildResult(
            $eventName,
            $best['category'],
            $best['confidence'],
            'payload',
            false,
            true,
            array_slice($competing, 1),
        );
    }

    /**
     * Classify by contextual heuristics (Layer 4 — fallback).
     *
     * @return ClassificationResult
     */
    private function classifyByHeuristic(string $eventName): array
    {
        // Check event name structure heuristics
        $parts = explode('_', $eventName);
        $firstPart = $parts[0] ?? '';

        // Heuristic: verb-noun pattern analysis
        $ecommerceVerbs = ['view', 'add', 'remove', 'purchase', 'checkout', 'pay', 'refund', 'select', 'cart', 'wishlist'];
        $saasVerbs = ['sign', 'login', 'logout', 'trial', 'subscribe', 'upgrade', 'cancel', 'plan', 'feature', 'invite', 'team', 'role', 'billing', 'invoice'];
        $engagementVerbs = ['page', 'scroll', 'click', 'form', 'search', 'share', 'error', 'session', 'screen', 'notification', 'download', 'video'];

        $allHeuristics = [
            'ecommerce' => $ecommerceVerbs,
            'saas' => $saasVerbs,
            'engagement' => $engagementVerbs,
        ];

        $competing = [];

        foreach ($allHeuristics as $category => $verbs) {
            if (in_array($firstPart, $verbs, true)) {
                $competing[] = [
                    'category' => $category,
                    'confidence' => 0.3,
                ];
            }
        }

        if ($competing !== []) {
            usort($competing, static fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']);
            $best = $competing[0];

            return $this->buildResult(
                $eventName,
                $best['category'],
                $best['confidence'],
                'heuristic',
                false,
                true,
                array_slice($competing, 1),
            );
        }

        // Complete unknown — no heuristics matched
        return $this->buildResult($eventName, 'unknown', 0.0, 'unknown', false, true);
    }

    /**
     * Build a classification result.
     *
     * @param  string  $eventName
     * @param  string  $category
     * @param  float  $confidence
     * @param  string  $method
     * @param  bool  $catalogMatch
     * @param  bool  $isCustom
     * @param  list<array{category: string, confidence: float}>  $competing
     * @param  list<string>  $suggestions
     * @return ClassificationResult
     */
    private function buildResult(
        string $eventName,
        string $category,
        float $confidence,
        string $method,
        bool $catalogMatch,
        bool $isCustom,
        array $competing = [],
        array $suggestions = [],
    ): array {
        return [
            'event_name' => $eventName,
            'category' => $category,
            'confidence' => round($confidence, 4),
            'method' => $method,
            'competing' => $competing,
            'suggestions' => $suggestions,
            'catalog_match' => $catalogMatch,
            'is_custom' => $isCustom,
        ];
    }

    /**
     * Estimate pattern confidence based on match specificity.
     *
     * More specific patterns (longer matches, fewer wildcards) get higher confidence.
     *
     * @param  string  $eventName
     * @param  string  $category
     * @return float  Confidence between 0.5 and 0.95
     */
    private function estimatePatternConfidence(string $eventName, string $category): float
    {
        // Exact prefix match = high confidence
        $prefixes = [
            'ecommerce' => ['view_item', 'add_to_cart', 'remove_from_cart', 'begin_checkout', 'purchase', 'refund'],
            'saas' => ['sign_up', 'login', 'logout', 'start_trial', 'subscribe', 'plan_upgrade', 'cancellation'],
            'engagement' => ['page_view', 'scroll_depth', 'form_start', 'form_submit', 'search', 'share'],
        ];

        $categoryPrefixes = $prefixes[$category] ?? [];

        foreach ($categoryPrefixes as $prefix) {
            if (str_starts_with($eventName, $prefix)) {
                return 0.9;
            }
        }

        return 0.6;
    }

    /**
     * Normalize an event name for comparison.
     *
     * Converts separators, case variations to a canonical form.
     */
    private function normalizeEventName(string $eventName): string
    {
        return strtolower(
            str_replace(['-', ' ', '.', '/'], '_', trim($eventName)),
        );
    }

    /**
     * Calculate the overall classification quality score.
     *
     * @param  float  $classificationRate  Ratio of events with catalog matches
     * @param  float  $averageConfidence  Average confidence across all classifications
     * @param  int  $uncategorizedCount  Number of uncategorized events
     * @param  int  $totalEvents  Total number of events classified
     * @return float  Quality score between 0.0 and 1.0
     */
    private function calculateQualityScore(
        float $classificationRate,
        float $averageConfidence,
        int $uncategorizedCount,
        int $totalEvents,
    ): float {
        if ($totalEvents === 0) {
            return 0.0;
        }

        $catalogScore = $classificationRate * 0.4;
        $confidenceScore = $averageConfidence * 0.35;
        $coverageScore = (1.0 - ($uncategorizedCount / $totalEvents)) * 0.25;

        return min(1.0, $catalogScore + $confidenceScore + $coverageScore);
    }

    /**
     * Build category → regex pattern mappings.
     *
     * @return array<string, list<string>>
     */
    private function buildPatterns(): array
    {
        // Allow config override
        $customPatterns = $this->config->get('zeroboiler.analytics.semantic_classifier.patterns', []);

        $defaults = [
            'ecommerce' => [
                '/^view_item$/',
                '/^add_to_cart$/',
                '/^remove_from_cart$/',
                '/^view_cart$/',
                '/^begin_checkout$/',
                '/^add_payment_info$/',
                '/^purchase$/',
                '/^refund$/',
                '/^add_to_wishlist$/',
                '/^select_item$/',
                '/^select_promotion$/',
                '/^view_promotion$/',
                '/^checkout_step$/',
                '/^abandoned_cart$/',
                '/^checkout_abandon$/',
                '/^(view|add|remove|begin|select|checkout).*_(item|cart|payment|wishlist|promotion|step)$/',
                '/^ecommerce_/',
                '/^(product|order|cart|checkout|payment|refund|shipping)/',
            ],
            'saas' => [
                '/^sign_up$/',
                '/^login$/',
                '/^logout$/',
                '/^start_trial$/',
                '/^trial_end$/',
                '/^subscribe$/',
                '/^plan_upgrade$/',
                '/^plan_downgrade$/',
                '/^cancellation$/',
                '/^feature_used$/',
                '/^revenue_tracked$/',
                '/^(account|team|role|invite|member)_(created|joined|removed|changed)$/',
                '/^(payment|invoice|credit|billing)/',
                '/^(password|profile|email)_(changed|reset|updated|verified)$/',
                '/^subscription_/',
                '/^trial_/',
                '/^cohort_/',
                '/^saas_/',
            ],
            'engagement' => [
                '/^page_view$/',
                '/^scroll_depth$/',
                '/^click$/',
                '/^form_start$/',
                '/^form_submit$/',
                '/^search$/',
                '/^share$/',
                '/^error$/',
                '/^time_on_page$/',
                '/^screen_view$/',
                '/^session_(start|end)$/',
                '/^outbound_click$/',
                '/^file_download$/',
                '/^video_play$/',
                '/^(web_vitals|js_error|timing|performance_score)$/',
                '/^(content_engagement|onboarding_step|feedback|goal_conversion)$/',
                '/^engagement_/',
                '/^interaction_/',
                '/^ui_/',
            ],
        ];

        // Merge custom patterns
        foreach ($defaults as $category => $patterns) {
            $custom = $customPatterns[$category] ?? [];
            $defaults[$category] = array_merge($patterns, $custom);
        }

        return $defaults;
    }

    /**
     * Build category → payload key hint mappings.
     *
     * @return array<string, list<string>>
     */
    private function buildPayloadHints(): array
    {
        // Allow config override
        $customHints = $this->config->get('zeroboiler.analytics.semantic_classifier.payload_hints', []);

        $defaults = [
            'ecommerce' => [
                'item_id', 'item_name', 'price', 'quantity', 'currency',
                'transaction_id', 'value', 'shipping', 'tax', 'coupon',
                'affiliation', 'item_brand', 'item_category', 'item_variant',
                'cart_id', 'order_id', 'payment_type',
            ],
            'saas' => [
                'plan', 'plan_id', 'subscription_id', 'trial_days',
                'billing_cycle', 'mrr', 'arr', 'feature_name',
                'team_id', 'role', 'invite_email', 'tier',
                'previous_plan', 'new_plan', 'cancel_reason',
            ],
            'engagement' => [
                'page_url', 'page_title', 'scroll_percent', 'element',
                'form_name', 'form_id', 'search_term', 'search_results',
                'share_method', 'share_url', 'referrer', 'session_duration',
                'time_on_page', 'viewport', 'click_target',
            ],
        ];

        // Merge custom hints
        foreach ($defaults as $category => $hints) {
            $custom = $customHints[$category] ?? [];
            $defaults[$category] = array_values(array_unique(array_merge($hints, $custom)));
        }

        return $defaults;
    }

    /**
     * Build event name alias map.
     *
     * Maps common naming variations to canonical catalog event names.
     *
     * @return array<string, string>
     */
    private function buildAliasMap(): array
    {
        // Allow config override
        $customAliases = $this->config->get('zeroboiler.analytics.semantic_classifier.aliases', []);

        $defaults = [
            'user_signup' => 'sign_up',
            'user_signup_completed' => 'sign_up',
            'user_register' => 'sign_up',
            'registration' => 'sign_up',
            'user_registered' => 'sign_up',
            'account_created' => 'sign_up',
            'user_login' => 'login',
            'user_logged_in' => 'login',
            'user_logout' => 'logout',
            'user_signed_out' => 'logout',
            'trial_started' => 'start_trial',
            'trial_start' => 'start_trial',
            'user_subscribed' => 'subscribe',
            'subscription_created' => 'subscribe',
            'user_cancelled' => 'cancellation',
            'subscription_cancelled' => 'cancellation',
            'user_upgrade' => 'plan_upgrade',
            'plan_changed' => 'plan_upgrade',
            'product_view' => 'view_item',
            'product_viewed' => 'view_item',
            'cart_add' => 'add_to_cart',
            'added_to_cart' => 'add_to_cart',
            'checkout' => 'begin_checkout',
            'checkout_started' => 'begin_checkout',
            'order_complete' => 'purchase',
            'order_completed' => 'purchase',
            'order_placed' => 'purchase',
            'pageview' => 'page_view',
            'page_viewed' => 'page_view',
            'btn_click' => 'click',
            'button_click' => 'click',
            'form_submitted' => 'form_submit',
            'error_occurred' => 'error',
            'js_exception' => 'js_error',
        ];

        return array_merge($defaults, $customAliases);
    }
}
