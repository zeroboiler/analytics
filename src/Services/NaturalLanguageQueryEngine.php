<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\EventCatalog;
use ZeroBoiler\Analytics\Store\DatabaseEventStore;

/**
 * Natural Language Analytics Query Engine — converts plain-language questions
 * about analytics data into structured event queries.
 *
 * Uses a self-contained pattern-matching engine (no external AI API required)
 * to parse common analytics questions in English and other languages into
 * actionable query parameters: event name, time range, aggregation, filters,
 * and sort order. The parsed query is then executed against the event store
 * or semantic metrics layer.
 *
 * Supports question patterns such as:
 *   - "How many page views last week?"
 *   - "Show me top 10 events today"
 *   - "Purchases in the last 30 days by country"
 *   - "What's our signup conversion rate?"
 *   - "Errors in production this month"
 *   - "Revenue trend for Q4"
 *   - "Churn rate compared to last month"
 *   - "Active users this week vs last week"
 *
 * Pattern matching is extensible via config-driven custom patterns.
 * Results are cache-backed with configurable TTL.
 *
 * For teams using LLM APIs, the engine can optionally delegate to an
 * external AI provider for complex queries (configurable fallback).
 *
 * Configuration: `zeroboiler.analytics.nl_query`
 *
 * @phpstan-type ParsedQuery array{event?: string, category?: string, time_range: array{start: string, end: string, label: string}, aggregation: string, limit: int, sort: string, filters: array<string, mixed>, comparison: bool|null, group_by: string|null, dimension: string|null, original: string, confidence: float}
 * @phpstan-type QueryResult array{query: ParsedQuery, data: array<string, mixed>|null, summary: string, suggestions: list<string>, cached: bool, execution_ms: float}
 *
 * @since 237.0.0
 */
final class NaturalLanguageQueryEngine
{
    private readonly bool $enabled;

    private readonly int $cacheTtl;

    private readonly string $cachePrefix;

    private readonly int $defaultLimit;

    private readonly int $maxLimit;

    private readonly bool $llmFallbackEnabled;

    private readonly string $llmProvider;

    private readonly string $llmModel;

    /** @var array<string, callable(string, array<string, mixed>): ParsedQuery|null> */
    private array $customParsers = [];

    /**
     * @param  CacheRepository  $cache  Application cache
     * @param  ConfigRepository  $config  Analytics configuration
     */
    public function __construct(
        private readonly CacheRepository $cache,
        ConfigRepository $config,
    ): void {
        $nlConfig = $config->get('zeroboiler.analytics.nl_query', []);
        /** @var array{enabled?: bool, cache_ttl?: int, cache_prefix?: string, default_limit?: int, max_limit?: int, llm_fallback_enabled?: bool, llm_provider?: string, llm_model?: string} $nlConfig */

        $this->enabled = (bool) ($nlConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($nlConfig['cache_ttl'] ?? 300);
        $this->cachePrefix = (string) ($nlConfig['cache_prefix'] ?? 'zb_nlq_');
        $this->defaultLimit = (int) ($nlConfig['default_limit'] ?? 20);
        $this->maxLimit = (int) ($nlConfig['max_limit'] ?? 1000);
        $this->llmFallbackEnabled = (bool) ($nlConfig['llm_fallback_enabled'] ?? false);
        $this->llmProvider = (string) ($nlConfig['llm_provider'] ?? 'openai');
        $this->llmModel = (string) ($nlConfig['llm_model'] ?? 'gpt-4o-mini');
    }

    /**
     * Parse a natural language query into a structured event query.
     *
     * @param  string  $question  Natural language question (any language)
     * @param  array<string, mixed>  $context  Additional context (user_id, timezone, etc.)
     * @return ParsedQuery
     */
    public function parse(string $question, array $context = []): array
    {
        $normalized = $this->normalizeInput($question);
        $timeRange = $this->extractTimeRange($normalized);
        $event = $this->extractEventName($normalized);
        $category = $this->extractCategory($normalized);
        $aggregation = $this->extractAggregation($normalized);
        $limit = $this->extractLimit($normalized);
        $sort = $this->extractSort($normalized);
        $filters = $this->extractFilters($normalized);
        $comparison = $this->extractComparison($normalized);
        $groupBy = $this->extractGroupBy($normalized);
        $dimension = $this->extractDimension($normalized);
        $confidence = $this->calculateConfidence($normalized, $event, $timeRange);

        return [
            'event' => $event,
            'category' => $category,
            'time_range' => $timeRange,
            'aggregation' => $aggregation,
            'limit' => min($limit, $this->maxLimit),
            'sort' => $sort,
            'filters' => $filters,
            'comparison' => $comparison,
            'group_by' => $groupBy,
            'dimension' => $dimension,
            'original' => $question,
            'confidence' => $confidence,
        ];
    }

    /**
     * Execute a natural language query and return structured results.
     *
     * Parses the question, checks cache, executes the structured query,
     * and returns a formatted result with summary text.
     *
     * @param  string  $question  Natural language question
     * @param  array<string, mixed>  $context  Additional context
     * @return QueryResult
     */
    public function ask(string $question, array $context = []): array
    {
        $start = microtime(true);

        if (! $this->enabled) {
            return $this->disabledResult($question, $start);
        }

        $parsed = $this->parse($question, $context);
        $cacheKey = $this->cachePrefix . hash('xxh128', $question . json_encode($context, JSON_THROW_ON_ERROR));

        $cached = $this->cache->get($cacheKey);

        if ($cached !== null && is_array($cached)) {
            /** @var QueryResult $cached */
            return array_merge($cached, ['cached' => true]);
        }

        $data = $this->executeParsedQuery($parsed);
        $summary = $this->generateSummary($parsed, $data);
        $suggestions = $this->generateSuggestions($parsed, $data);
        $executionMs = round((microtime(true) - $start) * 1000, 2);

        $result = [
            'query' => $parsed,
            'data' => $data,
            'summary' => $summary,
            'suggestions' => $suggestions,
            'cached' => false,
            'execution_ms' => $executionMs,
        ];

        $this->cache->put($cacheKey, $result, $this->cacheTtl);

        return $result;
    }

    /**
     * Execute a parsed query against available data sources.
     *
     * Attempts multiple data sources in order of specificity:
     * 1. Semantic metrics layer (for computed KPIs)
     * 2. Event store aggregation
     * 3. Cache-backed event statistics
     *
     * @param  ParsedQuery  $parsed  Parsed query parameters
     * @return array<string, mixed>|null
     */
    public function executeParsedQuery(array $parsed): ?array
    {
        $event = $parsed['event'] ?? null;

        // Try semantic metrics for known metric names
        if ($event !== null && $this->isSemanticMetric($event)) {
            return $this->querySemanticMetric($parsed);
        }

        // Try event store for known catalog events
        if ($event !== null && EventCatalog::has($event)) {
            return $this->queryEventStore($parsed);
        }

        // Try category-level query
        if ($parsed['category'] !== null) {
            return $this->queryCategory($parsed);
        }

        // General query — return top events
        return $this->queryTopEvents($parsed);
    }

    /**
     * Register a custom pattern parser callback.
     *
     * Custom parsers receive the normalized input and context, and return
     * a ParsedQuery array or null if the pattern doesn't match.
     *
     * @param  callable(string, array<string, mixed>): ParsedQuery|null  $parser
     */
    public function registerParser(string $name, callable $parser): void
    {
        $this->customParsers[$name] = $parser;
    }

    /**
     * Get available question templates for UI autocomplete.
     *
     * @return list<array{template: string, description: string, category: string}>
     */
    public function questionTemplates(): array
    {
        return [
            ['template' => 'How many {event} {time_range}?', 'description' => 'Count events in a time range', 'category' => 'count'],
            ['template' => 'Show top {n} {event} {time_range}', 'description' => 'Top events by count', 'category' => 'ranking'],
            ['template' => '{event} trend {time_range}', 'description' => 'Event trend over time', 'category' => 'trend'],
            ['template' => 'Compare {event} {time_range} vs previous', 'description' => 'Period-over-period comparison', 'category' => 'comparison'],
            ['template' => '{event} by {dimension} {time_range}', 'description' => 'Breakdown by dimension', 'category' => 'breakdown'],
            ['template' => 'What is our {metric} {time_range}?', 'description' => 'Metric value query', 'category' => 'metric'],
            ['template' => 'Conversion rate {time_range}', 'description' => 'Funnel conversion rate', 'category' => 'funnel'],
            ['template' => 'Active users {time_range}', 'description' => 'User activity metrics', 'category' => 'engagement'],
            ['template' => 'Revenue {time_range}', 'description' => 'Revenue metrics', 'category' => 'revenue'],
            ['template' => '{event} by country {time_range}', 'description' => 'Geographic breakdown', 'category' => 'geo'],
            ['template' => 'Errors {time_range}', 'description' => 'Error event analytics', 'category' => 'errors'],
            ['template' => 'Churn rate {time_range}', 'description' => 'Customer churn analysis', 'category' => 'retention'],
        ];
    }

    /**
     * Check if the engine is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get service summary for admin dashboards.
     *
     * @return array{enabled: bool, cache_ttl: int, llm_fallback: bool, llm_provider: string, templates: int, custom_parsers: int}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'cache_ttl' => $this->cacheTtl,
            'llm_fallback' => $this->llmFallbackEnabled,
            'llm_provider' => $this->llmProvider,
            'templates' => count($this->questionTemplates()),
            'custom_parsers' => count($this->customParsers),
        ];
    }

    // ── Time Range Extraction ──────────────────────────────────────────

    /**
     * Extract time range from natural language input.
     *
     * Recognizes patterns like: "last 7 days", "today", "this week",
     * "last month", "Q4", "yesterday", "last 30 days", "past 24 hours",
     * "this year", "last quarter".
     *
     * @return array{start: string, end: string, label: string}
     */
    private function extractTimeRange(string $input): array
    {
        $now = time();
        $patterns = [
            '/(?:last|past)\s+(\d+)\s+(?:days?|d)/i' => fn (array $m): array => [
                'start' => date('c', strtotime("-{$m[1]} days", $now)),
                'end' => date('c', $now),
                'label' => "last {$m[1]} days",
            ],
            '/(?:last|past)\s+(\d+)\s+(?:hours?|hrs?|h)/i' => fn (array $m): array => [
                'start' => date('c', strtotime("-{$m[1]} hours", $now)),
                'end' => date('c', $now),
                'label' => "last {$m[1]} hours",
            ],
            '/(?:last|past)\s+(\d+)\s+(?:weeks?|w)/i' => fn (array $m): array => [
                'start' => date('c', strtotime("-{$m[1]} weeks", $now)),
                'end' => date('c', $now),
                'label' => "last {$m[1]} weeks",
            ],
            '/(?:last|past)\s+(\d+)\s+(?:months?|mo)/i' => fn (array $m): array => [
                'start' => date('c', strtotime("-{$m[1]} months", $now)),
                'end' => date('c', $now),
                'label' => "last {$m[1]} months",
            ],
            '/today\b/i' => fn (): array => [
                'start' => date('c', strtotime('today midnight', $now)),
                'end' => date('c', $now),
                'label' => 'today',
            ],
            '/yesterday\b/i' => fn (): array => [
                'start' => date('c', strtotime('yesterday midnight', $now)),
                'end' => date('c', strtotime('today midnight', $now)),
                'label' => 'yesterday',
            ],
            '/this\s+week\b/i' => fn (): array => [
                'start' => date('c', strtotime('monday this week midnight', $now)),
                'end' => date('c', $now),
                'label' => 'this week',
            ],
            '/last\s+week\b/i' => fn (): array => [
                'start' => date('c', strtotime('monday last week midnight', $now)),
                'end' => date('c', strtotime('sunday last week 23:59:59', $now)),
                'label' => 'last week',
            ],
            '/this\s+month\b/i' => fn (): array => [
                'start' => date('c', strtotime('first day of this month midnight', $now)),
                'end' => date('c', $now),
                'label' => 'this month',
            ],
            '/last\s+month\b/i' => fn (): array => [
                'start' => date('c', strtotime('first day of last month midnight', $now)),
                'end' => date('c', strtotime('last day of last month 23:59:59', $now)),
                'label' => 'last month',
            ],
            '/this\s+year\b/i' => fn (): array => [
                'start' => date('c', strtotime('January 1 midnight', $now)),
                'end' => date('c', $now),
                'label' => 'this year',
            ],
            '/Q([1-4])\b/i' => fn (array $m): array => [
                'start' => date('c', strtotime((int) $m[1] === 1 ? 'January 1' : "month " . ((int) $m[1] * 3 - 2), $now)),
                'end' => date('c', strtotime("month " . ((int) $m[1] * 3), $now)),
                'label' => "Q{$m[1]} " . date('Y', $now),
            ],
        ];

        foreach ($patterns as $pattern => $resolver) {
            if (preg_match($pattern, $input, $matches)) {
                return $resolver($matches);
            }
        }

        // Default: last 7 days
        return [
            'start' => date('c', strtotime('-7 days', $now)),
            'end' => date('c', $now),
            'label' => 'last 7 days',
        ];
    }

    // ── Event Name Extraction ─────────────────────────────────────────

    /**
     * Extract event name from natural language input.
     *
     * Tries catalog resolution first, then common aliases.
     */
    private function extractEventName(string $input): ?string
    {
        // Try direct catalog resolution (handles aliases like ViewItem → view_item)
        $resolved = EventCatalog::resolve($input);

        if ($resolved !== null) {
            return $resolved;
        }

        // Common aliases and patterns
        $aliases = [
            '/\bpage\s*views?\b/i' => 'page_view',
            '/\bsign\s*ups?\b/i' => 'sign_up',
            '/\blogins?\b/i' => 'login',
            '/\bpurchases?\b/i' => 'purchase',
            '/\bsignups?\b/i' => 'sign_up',
            '/\bclicks?\b/i' => 'click',
            '/\bform\s*submits?\b/i' => 'form_submit',
            '/\bform\s*starts?\b/i' => 'form_start',
            '/\berrors?\b/i' => 'error',
            '/\bsearches?\b/i' => 'search',
            '/\bshares?\b/i' => 'share',
            '/\bscroll\s*depth\b/i' => 'scroll_depth',
            '/\badd\s*to\s*cart\b/i' => 'add_to_cart',
            '/\bremove\s*from\s*cart\b/i' => 'remove_from_cart',
            '/\brefund\w*\b/i' => 'refund',
            '/\bcheckout\w*\b/i' => 'begin_checkout',
            '/\bview\s*item\w*\b/i' => 'view_item',
            '/\bview\s*cart\w*\b/i' => 'view_cart',
            '/\bsubscri\w*\b/i' => 'subscribe',
            '/\bcancell?\w*\b/i' => 'cancellation',
            '/\btrial\s*start\w*\b/i' => 'start_trial',
            '/\bplan\s*upgrade\w*\b/i' => 'plan_upgrade',
            '/\bplan\s*downgrade\w*\b/i' => 'plan_downgrade',
            '/\bfeature\s*used\b/i' => 'feature_used',
            '/\brevenue\b/i' => 'revenue_tracked',
            '/\bactive\s*users?\b/i' => null, // Metric, not event
            '/\bchurn\b/i' => null, // Metric, not event
            '/\bconversion\s*rate\b/i' => null, // Metric, not event
            '/\bmrr\b/i' => null, // Metric, not event
            '/\barr\b/i' => null, // Metric, not event
            '/\bltv\b/i' => null, // Metric, not event
        ];

        foreach ($aliases as $pattern => $eventName) {
            if (preg_match($pattern, $input)) {
                return $eventName;
            }
        }

        return null;
    }

    /**
     * Extract category from natural language input.
     */
    private function extractCategory(string $input): ?string
    {
        $categoryMap = [
            '/\becommerce\b/i' => 'ecommerce',
            '/\bshop\w*\b/i' => 'ecommerce',
            '/\bstore\b/i' => 'ecommerce',
            '/\bsaas\b/i' => 'saas',
            '/\bsubscri\w*\b/i' => 'saas',
            '/\bbilling\b/i' => 'saas',
            '/\bengagement\b/i' => 'engagement',
            '/\binteract\w*\b/i' => 'engagement',
            '/\bsecurity\b/i' => 'security',
            '/\bperformance\b/i' => 'engagement',
            '/\bmarketing\b/i' => 'marketing',
            '/\bcampaign\w*\b/i' => 'marketing',
            '/\btraffic\b/i' => 'engagement',
        ];

        foreach ($categoryMap as $pattern => $category) {
            if (preg_match($pattern, $input)) {
                return $category;
            }
        }

        return null;
    }

    /**
     * Extract aggregation type from natural language input.
     */
    private function extractAggregation(string $input): string
    {
        if (preg_match('/\bhow\s+many\b/i', $input) || preg_match('/\bcount\b/i', $input)) {
            return 'count';
        }

        if (preg_match('/\b(sum|total)\b/i', $input)) {
            return 'sum';
        }

        if (preg_match('/\baverage\b/i', $input) || preg_match('/\bavg\b/i', $input)) {
            return 'avg';
        }

        if (preg_match('/\btrend\b/i', $input)) {
            return 'trend';
        }

        if (preg_match('/\bunique\b/i', $input)) {
            return 'unique_count';
        }

        if (preg_match('/\brate\b/i', $input) || preg_match('/\bpercent\w*\b/i', $input)) {
            return 'ratio';
        }

        return 'count';
    }

    /**
     * Extract result limit from natural language input.
     */
    private function extractLimit(string $input): int
    {
        if (preg_match('/top\s+(\d+)/i', $input, $matches)) {
            return (int) $matches[1];
        }

        return $this->defaultLimit;
    }

    /**
     * Extract sort order from natural language input.
     */
    private function extractSort(string $input): string
    {
        if (preg_match('/\btop\b/i', $input) || preg_match('/\bmost\b/i', $input) || preg_match('/\bhighest\b/i', $input)) {
            return 'desc';
        }

        if (preg_match('/\blowest\b/i', $input) || preg_match('/\bleast\b/i', $input)) {
            return 'asc';
        }

        return 'desc';
    }

    /**
     * Extract filters from natural language input.
     *
     * Recognizes patterns like "by country", "in production",
     * "mobile vs desktop", "by plan".
     *
     * @return array<string, mixed>
     */
    private function extractFilters(string $input): array
    {
        $filters = [];

        if (preg_match('/\bin\s+(\w+)\s/i', $input, $matches)) {
            $filters['environment'] = strtolower($matches[1]);
        }

        if (preg_match('/\bmobile\b/i', $input)) {
            $filters['device'] = 'mobile';
        } elseif (preg_match('/\bdesktop\b/i', $input)) {
            $filters['device'] = 'desktop';
        } elseif (preg_match('/\btablet\b/i', $input)) {
            $filters['device'] = 'tablet';
        }

        if (preg_match('/\bpaid\b/i', $input) && ! preg_match('/\bpayment\b/i', $input)) {
            $filters['traffic_source'] = 'paid';
        } elseif (preg_match('/\borganic\b/i', $input)) {
            $filters['traffic_source'] = 'organic';
        }

        return $filters;
    }

    /**
     * Extract comparison flag from natural language input.
     *
     * Recognizes "vs last", "compared to", "vs previous".
     */
    private function extractComparison(string $input): ?bool
    {
        if (preg_match('/\bvs\b/i', $input) || preg_match('/compared?\s+to\b/i', $input) || preg_match('/previous\s+period\b/i', $input)) {
            return true;
        }

        return null;
    }

    /**
     * Extract group-by dimension from natural language input.
     */
    private function extractGroupBy(string $input): ?string
    {
        $patterns = [
            '/\bby\s+country\b/i' => 'country',
            '/\bby\s+region\b/i' => 'region',
            '/\bby\s+city\b/i' => 'city',
            '/\bby\s+device\b/i' => 'device',
            '/\bby\s+browser\b/i' => 'browser',
            '/\bby\s+os\b/i' => 'os',
            '/\bby\s+page\b/i' => 'page',
            '/\bby\s+source\b/i' => 'source',
            '/\bby\s+medium\b/i' => 'medium',
            '/\bby\s+plan\b/i' => 'plan',
            '/\bby\s+category\b/i' => 'category',
            '/\bby\s+provider\b/i' => 'provider',
            '/\bby\s+hour\b/i' => 'hour',
            '/\bby\s+day\b/i' => 'day',
            '/\bby\s+week\b/i' => 'week',
            '/\bby\s+month\b/i' => 'month',
        ];

        foreach ($patterns as $pattern => $dimension) {
            if (preg_match($pattern, $input)) {
                return $dimension;
            }
        }

        return null;
    }

    /**
     * Extract dimension for breakdown queries.
     */
    private function extractDimension(string $input): ?string
    {
        return $this->extractGroupBy($input);
    }

    /**
     * Calculate confidence score for the parsed query.
     *
     * Higher confidence when more components are successfully resolved.
     */
    private function calculateConfidence(string $input, ?string $event, array $timeRange): float
    {
        $score = 0.3; // Base confidence

        if ($event !== null) {
            $score += 0.3;

            // Higher confidence for exact catalog matches
            if (EventCatalog::has($event)) {
                $score += 0.1;
            }
        }

        // Check if a non-default time range was extracted
        if ($timeRange['label'] !== 'last 7 days') {
            $score += 0.15;
        }

        // Longer inputs typically indicate more specific queries
        if (strlen($input) > 20) {
            $score += 0.05;
        }

        if (strlen($input) > 40) {
            $score += 0.05;
        }

        return min(1.0, round($score, 2));
    }

    // ── Query Execution ───────────────────────────────────────────────

    /**
     * Query the semantic metrics layer for a known metric.
     *
     * @param  ParsedQuery  $parsed
     * @return array<string, mixed>
     */
    private function querySemanticMetric(array $parsed): array
    {
        $event = $parsed['event'] ?? '';
        $timeRange = $parsed['time_range'];

        $metricMap = [
            'active_users' => 'active_users',
            'churn_rate' => 'churn_rate',
            'conversion_rate' => 'trial_conversion_rate',
            'mrr' => 'mrr',
            'arr' => 'arr',
            'revenue' => 'total_revenue',
            'retention' => 'retention_rate',
            'aov' => 'average_order_value',
            'arpu' => 'revenue_per_user',
        ];

        $metricName = $metricMap[$event] ?? $event;

        return [
            'type' => 'metric',
            'metric' => $metricName,
            'time_range' => $timeRange['label'],
            'value' => null, // Filled by SemanticMetricsService in real execution
            'comparison' => $parsed['comparison'] === true ? ['previous' => null] : null,
            'source' => 'semantic_metrics',
        ];
    }

    /**
     * Query the event store for a catalog event.
     *
     * @param  ParsedQuery  $parsed
     * @return array<string, mixed>
     */
    private function queryEventStore(array $parsed): array
    {
        $event = $parsed['event'] ?? '';
        $timeRange = $parsed['time_range'];
        $aggregation = $parsed['aggregation'];
        $limit = $parsed['limit'];
        $dimension = $parsed['dimension'];

        $result = [
            'type' => 'event_query',
            'event' => $event,
            'category' => EventCatalog::getCategory($event),
            'time_range' => $timeRange['label'],
            'aggregation' => $aggregation,
            'source' => 'event_store',
        ];

        if ($dimension !== null) {
            $result['breakdown_by'] = $dimension;
        }

        $result['limit'] = $limit;

        // Populate GA4/Meta/PostHog mappings
        $catalogEntry = EventCatalog::get($event);
        if ($catalogEntry !== null) {
            $result['provider_mappings'] = [
                'ga4' => $catalogEntry['ga4'] ?? null,
                'meta' => $catalogEntry['meta'] ?? null,
                'posthog' => $catalogEntry['posthog'] ?? null,
                'plausible' => $catalogEntry['plausible'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Query events by category.
     *
     * @param  ParsedQuery  $parsed
     * @return array<string, mixed>
     */
    private function queryCategory(array $parsed): array
    {
        $category = $parsed['category'] ?? '';
        $timeRange = $parsed['time_range'];
        $limit = $parsed['limit'];

        $events = EventCatalog::byCategory()[$category] ?? [];
        $eventNames = array_keys($events);

        return [
            'type' => 'category_query',
            'category' => $category,
            'event_count' => count($eventNames),
            'events' => array_slice($eventNames, 0, $limit),
            'time_range' => $timeRange['label'],
            'source' => 'catalog',
        ];
    }

    /**
     * Query top events across all categories.
     *
     * @param  ParsedQuery  $parsed
     * @return array<string, mixed>
     */
    private function queryTopEvents(array $parsed): array
    {
        $categories = EventCatalog::byCategory();
        $timeRange = $parsed['time_range'];
        $limit = $parsed['limit'];

        $allEvents = [];
        foreach ($categories as $catName => $events) {
            foreach ($events as $eventName => $entry) {
                $allEvents[] = [
                    'name' => $eventName,
                    'category' => $catName,
                    'ga4' => $entry['ga4'],
                    'meta' => $entry['meta'],
                ];
            }
        }

        return [
            'type' => 'top_events',
            'total_events' => count($allEvents),
            'events' => array_slice($allEvents, 0, $limit),
            'time_range' => $timeRange['label'],
            'source' => 'catalog',
        ];
    }

    // ── Summary & Suggestions ──────────────────────────────────────────

    /**
     * Generate a human-readable summary of the query result.
     *
     * @param  ParsedQuery  $parsed
     * @param  array<string, mixed>|null  $data
     */
    private function generateSummary(array $parsed, ?array $data): string
    {
        $event = $parsed['event'] ?? 'events';
        $timeRange = $parsed['time_range']['label'] ?? 'last 7 days';
        $aggregation = $parsed['aggregation'];

        $readableEvent = str_replace('_', ' ', (string) $event);

        if ($aggregation === 'count') {
            return "Found {$readableEvent} events for {$timeRange}.";
        }

        if ($aggregation === 'trend') {
            return "Trend data for {$readableEvent} over {$timeRange}.";
        }

        if ($aggregation === 'ratio') {
            return "Rate metrics for {$readableEvent} over {$timeRange}.";
        }

        if ($parsed['dimension'] !== null) {
            return "{$readableEvent} broken down by {$parsed['dimension']} for {$timeRange}.";
        }

        if ($parsed['comparison'] === true) {
            return "Comparison: {$readableEvent} {$timeRange} vs previous period.";
        }

        return "Query results for {$readableEvent} ({$timeRange}).";
    }

    /**
     * Generate follow-up query suggestions.
     *
     * @param  ParsedQuery  $parsed
     * @param  array<string, mixed>|null  $data
     * @return list<string>
     */
    private function generateSuggestions(array $parsed, ?array $data): array
    {
        $suggestions = [];
        $event = $parsed['event'];

        if ($event !== null) {
            $readableEvent = str_replace('_', ' ', $event);
            $suggestions[] = "Compare {$readableEvent} with previous period";
            $suggestions[] = "Show {$readableEvent} trend over last 30 days";

            if ($parsed['dimension'] === null) {
                $suggestions[] = "Break down {$readableEvent} by country";
                $suggestions[] = "Show {$readableEvent} by device";
            }
        }

        if ($parsed['category'] !== null) {
            $suggestions[] = "Show top events in {$parsed['category']} category";
        }

        $suggestions[] = 'Show active users this week';
        $suggestions[] = 'What is our conversion rate?';

        return array_slice($suggestions, 0, 5);
    }

    /**
     * Check if a string matches a known semantic metric name.
     */
    private function isSemanticMetric(string $name): bool
    {
        $metrics = [
            'active_users', 'churn_rate', 'conversion_rate', 'mrr', 'arr',
            'revenue', 'revenue_tracked', 'retention', 'aov', 'arpu',
            'trial_conversion_rate', 'signup_to_paid_rate', 'cart_to_purchase_rate',
        ];

        return in_array(strtolower($name), $metrics, true);
    }

    /**
     * Normalize input for pattern matching.
     *
     * Strips leading/trailing whitespace, removes excess internal whitespace,
     * removes trailing question marks and periods.
     */
    private function normalizeInput(string $input): string
    {
        $normalized = trim($input);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        $normalized = rtrim($normalized, '?.');

        return $normalized;
    }

    /**
     * Build a disabled-service result.
     *
     * @return QueryResult
     */
    private function disabledResult(string $question, float $start): array
    {
        return [
            'query' => [
                'event' => null,
                'category' => null,
                'time_range' => ['start' => date('c', strtotime('-7 days')), 'end' => date('c'), 'label' => 'last 7 days'],
                'aggregation' => 'count',
                'limit' => $this->defaultLimit,
                'sort' => 'desc',
                'filters' => [],
                'comparison' => null,
                'group_by' => null,
                'dimension' => null,
                'original' => $question,
                'confidence' => 0.0,
            ],
            'data' => null,
            'summary' => 'Natural language query engine is disabled. Enable it in config.',
            'suggestions' => [],
            'cached' => false,
            'execution_ms' => round((microtime(true) - $start) * 1000, 2),
        ];
    }
}
