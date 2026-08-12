<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Events\Ecommerce\EcommerceEvents;
use ZeroBoiler\Analytics\Events\SaaS\SaaSEvents;
use ZeroBoiler\Analytics\Events\Engagement\EngagementEvents;

/**
 * ML-ready event classification and auto-tagging engine.
 *
 * Automatically classifies analytics events into semantic categories
 * and applies tags based on event name, parameter structure, and
 * catalog metadata. Designed as a preprocessing layer for ML models
 * and advanced analytics pipelines.
 *
 * Categories:
 * - **conversion** — Events representing successful business outcomes
 * - **intent** — Events indicating user intent or interest
 * - **engagement** — Events measuring user interaction depth
 * - **navigation** — Events tracking page/view transitions
 * - **transaction** — Events related to purchase/commerce
 * - **identity** — Events related to user authentication/registration
 * - **error** — Events representing system/user errors
 * - **search** — Events related to search behavior
 *
 * Tags are automatically applied based on:
 * - Event name patterns (regex-based)
 * - Parameter presence and values
 * - Catalog category membership
 * - Event source (server-side, client-side, API)
 *
 * @since 21.0.0
 */
final class EventClassificationService
{
    /** @var array<string, list<string>> Pattern-based classification rules */
    private const NAME_PATTERNS = [
        'conversion' => [
            '/purchase/',
            '/checkout_complete/',
            '/subscription/',
            '/trial_start/',
            '/plan_upgrade/',
            '/signup_complete/',
            '/lead_generated/',
        ],
        'intent' => [
            '/add_to_cart/',
            '/add_to_wishlist/',
            '/begin_checkout/',
            '/view_item/',
            '/product_view/',
            '/pricing_view/',
            '/demo_request/',
            '/trial_start/',
        ],
        'engagement' => [
            '/scroll_depth/',
            '/time_on_page/',
            '/click/',
            '/form_start/',
            '/form_submit/',
            '/share/',
            '/video_play/',
            '/video_complete/',
            '/feature_used/',
        ],
        'navigation' => [
            '/page_view/',
            '/navigation/',
            '/route_change/',
            '/tab_switch/',
            '/section_view/',
        ],
        'transaction' => [
            '/purchase/',
            '/refund/',
            '/add_payment/',
            '/remove_from_cart/',
            '/cart_update/',
        ],
        'identity' => [
            '/sign_up/',
            '/login/',
            '/logout/',
            '/register/',
            '/account_create/',
            '/profile_update/',
            '/password_reset/',
            '/email_verify/',
        ],
        'error' => [
            '/error/',
            '/exception/',
            '/crash/',
            '/validation_error/',
            '/api_error/',
        ],
        'search' => [
            '/search/',
            '/filter/',
            '/sort/',
        ],
    ];

    /**
     * @var array<string, list<string>> Parameter-based classification rules
     * Maps parameter key names to categories
     */
    private const PARAM_INDICATORS = [
        'conversion' => ['revenue', 'value', 'transaction_id', 'order_id', 'subscription_id'],
        'intent' => ['product_id', 'item_id', 'sku', 'category', 'variant'],
        'engagement' => ['scroll_depth', 'time_spent', 'click_count', 'interaction_count', 'form_id'],
        'navigation' => ['page_url', 'referrer', 'previous_page', 'navigation_method'],
        'transaction' => ['payment_method', 'currency', 'discount', 'tax', 'shipping', 'coupon'],
        'identity' => ['user_email', 'auth_method', 'login_type', 'registration_method'],
        'error' => ['error_message', 'error_code', 'stack_trace', 'error_type'],
        'search' => ['search_term', 'search_query', 'search_results_count', 'search_category'],
    ];

    /**
     * Classify a single event.
     *
     * @return array{primary_category: string, confidence: float, categories: array<string, float>, tags: list<string>, metadata: array<string, mixed>}
     */
    public function classify(AnalyticsEvent $event): array
    {
        $categories = $this->matchCategories($event);
        $tags = $this->extractTags($event, $categories);

        arsort($categories);

        $primaryCategory = array_key_first($categories) ?? 'unclassified';
        $primaryConfidence = array_shift($categories) ?? 0.0;

        $metadata = $this->buildMetadata($event, $primaryCategory, $primaryConfidence);

        return [
            'primary_category' => $primaryCategory,
            'confidence' => round($primaryConfidence, 2),
            'categories' => $categories,
            'tags' => $tags,
            'metadata' => $metadata,
        ];
    }

    /**
     * Classify multiple events and return aggregate classification statistics.
     *
     * @param  list<AnalyticsEvent>  $events
     * @return array{total_events: int, category_distribution: array<string, int>, tag_frequency: array<string, int>, avg_confidence: float, unclassified_count: int}
     */
    public function classifyBatch(array $events): array
    {
        if ($events === []) {
            return [
                'total_events' => 0,
                'category_distribution' => [],
                'tag_frequency' => [],
                'avg_confidence' => 0.0,
                'unclassified_count' => 0,
            ];
        }

        $categoryDistribution = [];
        $tagFrequency = [];
        $totalConfidence = 0.0;
        $unclassified = 0;

        foreach ($events as $event) {
            $result = $this->classify($event);

            $primary = $result['primary_category'];
            $categoryDistribution[$primary] = ($categoryDistribution[$primary] ?? 0) + 1;

            foreach ($result['tags'] as $tag) {
                $tagFrequency[$tag] = ($tagFrequency[$tag] ?? 0) + 1;
            }

            $totalConfidence += $result['confidence'];

            if ($primary === 'unclassified') {
                $unclassified++;
            }
        }

        $count = count($events);

        return [
            'total_events' => $count,
            'category_distribution' => $categoryDistribution,
            'tag_frequency' => $tagFrequency,
            'avg_confidence' => round($totalConfidence / $count, 2),
            'unclassified_count' => $unclassified,
        ];
    }

    /**
     * Get all available classification categories with descriptions.
     *
     * @return array<string, array{description: string, example_events: list<string>, example_params: list<string>}>
     */
    public function categories(): array
    {
        return [
            'conversion' => [
                'description' => 'Successful business outcomes — purchases, subscriptions, signups',
                'example_events' => ['purchase', 'subscription', 'sign_up'],
                'example_params' => ['revenue', 'transaction_id', 'order_id'],
            ],
            'intent' => [
                'description' => 'User intent signals — product views, cart additions, pricing page views',
                'example_events' => ['view_item', 'add_to_cart', 'pricing_view'],
                'example_params' => ['product_id', 'sku', 'category'],
            ],
            'engagement' => [
                'description' => 'User interaction depth — clicks, scrolls, form interactions, shares',
                'example_events' => ['scroll_depth', 'click', 'form_submit', 'share'],
                'example_params' => ['scroll_depth', 'time_spent', 'click_count'],
            ],
            'navigation' => [
                'description' => 'Page and route transitions — page views, navigation events',
                'example_events' => ['page_view', 'route_change', 'tab_switch'],
                'example_params' => ['page_url', 'referrer', 'previous_page'],
            ],
            'transaction' => [
                'description' => 'Commerce-related events — purchases, refunds, payment processing',
                'example_events' => ['purchase', 'refund', 'add_payment'],
                'example_params' => ['payment_method', 'currency', 'discount'],
            ],
            'identity' => [
                'description' => 'Authentication and registration — logins, signups, profile updates',
                'example_events' => ['login', 'sign_up', 'logout', 'profile_update'],
                'example_params' => ['auth_method', 'login_type', 'registration_method'],
            ],
            'error' => [
                'description' => 'System and user errors — exceptions, validation failures, API errors',
                'example_events' => ['error', 'exception', 'validation_error'],
                'example_params' => ['error_message', 'error_code', 'stack_trace'],
            ],
            'search' => [
                'description' => 'Search behavior — queries, filters, sorting',
                'example_events' => ['search', 'search_results', 'filter_applied'],
                'example_params' => ['search_term', 'search_query', 'search_results_count'],
            ],
        ];
    }

    /**
     * Extract classification tags from an event.
     *
     * Tags include: category tags, source tags, parameter-based tags,
     * identity tags, and value-based tags.
     *
     * @param  AnalyticsEvent  $event
     * @param  array<string, float>  $categories
     * @return list<string>
     */
    public function extractTags(AnalyticsEvent $event, array $categories = []): array
    {
        $tags = [];

        // Category tags
        foreach ($categories as $category => $confidence) {
            if ($confidence > 0.1) {
                $tags[] = "cat:{$category}";
            }
        }

        // Source tags
        if ($event->source !== null) {
            $tags[] = "source:{$event->source}";
        } else {
            $tags[] = 'source:unknown';
        }

        // Identity tags
        if ($event->userId !== null && $event->userId !== '') {
            $tags[] = 'has_user_id';
        }
        if ($event->clientId !== null && $event->clientId !== '') {
            $tags[] = 'has_client_id';
        }

        // Value-based tags
        if (isset($event->params['value']) && is_numeric($event->params['value']) && (float) $event->params['value'] > 0) {
            $tags[] = 'has_monetary_value';
        }

        if (isset($event->params['revenue']) && is_numeric($event->params['revenue']) && (float) $event->params['revenue'] > 0) {
            $tags[] = 'has_revenue';
        }

        // Catalog membership tag
        if (EventCatalog::has($event->name)) {
            $tags[] = 'catalog:registered';

            // Determine catalog source
            if (EcommerceEvents::has($event->name)) {
                $tags[] = 'catalog:ecommerce';
            }
            if (SaaSEvents::has($event->name)) {
                $tags[] = 'catalog:saas';
            }
            if (EngagementEvents::has($event->name)) {
                $tags[] = 'catalog:engagement';
            }
        } else {
            $tags[] = 'catalog:custom';
        }

        return array_values(array_unique($tags));
    }

    /**
     * Get the classification confidence for a specific category.
     *
     * @return array{category: string, confidence: float, match_source: string, matched_patterns: list<string>}
     */
    public function categoryConfidence(AnalyticsEvent $event, string $category): array
    {
        $patterns = self::NAME_PATTERNS[$category] ?? [];
        $indicators = self::PARAM_INDICATORS[$category] ?? [];
        $matchedPatterns = [];
        $matchSource = 'none';
        $confidence = 0.0;

        // Check name patterns
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $event->name)) {
                $matchedPatterns[] = "name:{$pattern}";
                $confidence += 40.0;
                $matchSource = 'name_pattern';
            }
        }

        // Check parameter indicators
        foreach ($indicators as $indicator) {
            if (array_key_exists($indicator, $event->params)) {
                $matchedPatterns[] = "param:{$indicator}";
                $confidence += 20.0;
                if ($matchSource === 'none') {
                    $matchSource = 'param_indicator';
                }
            }
        }

        // Check catalog category
        if (EventCatalog::has($event->name)) {
            $entry = EventCatalog::get($event->name);
            $entryCategory = $entry['category'] ?? null;
            if ($entryCategory === $category) {
                $matchedPatterns[] = 'catalog:category_match';
                $confidence += 40.0;
                $matchSource = 'catalog_category';
            }
        }

        return [
            'category' => $category,
            'confidence' => min(100.0, round($confidence, 2)),
            'match_source' => $matchSource,
            'matched_patterns' => $matchedPatterns,
        ];
    }

    /**
     * Match event against all classification categories.
     *
     * @return array<string, float> Category => confidence (0-100)
     */
    private function matchCategories(AnalyticsEvent $event): array
    {
        $scores = [];

        foreach (array_keys(self::NAME_PATTERNS) as $category) {
            $result = $this->categoryConfidence($event, $category);
            if ($result['confidence'] > 0) {
                $scores[$category] = $result['confidence'];
            }
        }

        return $scores;
    }

    /**
     * Build metadata for classification result.
     *
     * @return array<string, mixed>
     */
    private function buildMetadata(AnalyticsEvent $event, string $primaryCategory, float $confidence): array
    {
        return [
            'event_name' => $event->name,
            'param_count' => count($event->params),
            'has_user_id' => $event->userId !== null,
            'has_client_id' => $event->clientId !== null,
            'in_catalog' => EventCatalog::has($event->name),
            'classified_at' => (new \DateTimeImmutable)->format('c'),
            'classifier_version' => '1.0.0',
            'primary_category' => $primaryCategory,
            'confidence_threshold' => 0.3,
            'is_high_confidence' => $confidence >= 60.0,
        ];
    }
}
