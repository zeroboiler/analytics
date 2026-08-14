<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

/**
 * Fluent query builder for analytics event data.
 *
 * Provides a declarative DSL for composing complex analytics queries
 * without touching low-level cache operations. Queries are executed
 * against the EventQueryEngine.
 *
 * @example
 *   $results = AnalyticsQueryBuilder::make()
 *       ->events(['sign_up', 'login', 'subscribe'])
 *       ->period(14)
 *       ->groupBy('day')
 *       ->execute();
 *
 *   $funnel = AnalyticsQueryBuilder::make()
 *       ->funnel('signup')
 *       ->steps(['sign_up', 'email_verified', 'subscribe'])
 *       ->period(30)
 *       ->execute();
 *
 * @since 1.0.0
 */
final class AnalyticsQueryBuilder
{
    /** @var list<string> */
    private array $events = [];

    /** @var int */
    private int $periodDays = 7;

    /** @var 'day'|'hour'|'week'|'category' */
    private string $groupBy = 'day';

    /** @var string|null */
    private ?string $funnelName = null;

    /** @var list<string> */
    private array $funnelSteps = [];

    /** @var 'up'|'down'|'all' */
    private string $trendDirection = 'all';

    /** @var int */
    private int $limit = 10;

    /** @var string */
    private string $conversionFrom = '';

    /** @var string */
    private string $conversionTo = '';

    /** @var string */
    private string $queryType = 'time_series';

    private function __construct() {}

    /**
     * Create a new query builder instance.
     */
    public static function make(): self
    {
        return new self();
    }

    /**
     * Set the event names to query.
     *
     * @param  list<string>  $events  Event names
     * @return self
     */
    public function events(array $events): self
    {
        $this->events = $events;

        return $this;
    }

    /**
     * Add a single event to the query.
     *
     * @return self
     */
    public function event(string $eventName): self
    {
        $this->events[] = $eventName;

        return $this;
    }

    /**
     * Set the analysis period in days.
     *
     * @param  int  $days  Number of days (1-90)
     * @return self
     */
    public function period(int $days): self
    {
        $this->periodDays = max(1, min(90, $days));

        return $this;
    }

    /**
     * Set the grouping dimension.
     *
     * @param  'day'|'hour'|'week'|'category'  $groupBy  Grouping type
     * @return self
     */
    public function groupBy(string $groupBy): self
    {
        $this->groupBy = $groupBy;

        return $this;
    }

    /**
     * Configure a funnel query.
     *
     * @param  string  $name  Funnel name
     * @param  list<string>  $steps  Ordered step event names
     * @return self
     */
    public function funnel(string $name, array $steps = []): self
    {
        $this->queryType = 'funnel';
        $this->funnelName = $name;
        $this->funnelSteps = $steps;

        return $this;
    }

    /**
     * Set funnel steps.
     *
     * @param  list<string>  $steps  Ordered step event names
     * @return self
     */
    public function steps(array $steps): self
    {
        $this->funnelSteps = $steps;

        return $this;
    }

    /**
     * Configure a trending events query.
     *
     * @param  'up'|'down'|'all'  $direction  Filter direction
     * @return self
     */
    public function trending(string $direction = 'all'): self
    {
        $this->queryType = 'trending';
        $this->trendDirection = $direction;

        return $this;
    }

    /**
     * Set the result limit.
     *
     * @param  int  $limit  Max results (1-50)
     * @return self
     */
    public function limit(int $limit): self
    {
        $this->limit = max(1, min(50, $limit));

        return $this;
    }

    /**
     * Configure a conversion rate query.
     *
     * @param  string  $fromEvent  Source event
     * @param  string  $toEvent  Target event
     * @return self
     */
    public function conversion(string $fromEvent, string $toEvent): self
    {
        $this->queryType = 'conversion';
        $this->conversionFrom = $fromEvent;
        $this->conversionTo = $toEvent;

        return $this;
    }

    /**
     * Configure a category breakdown query.
     *
     * @return self
     */
    public function categoryBreakdown(): self
    {
        $this->queryType = 'category_breakdown';

        return $this;
    }

    /**
     * Configure a retention cohort query.
     *
     * @param  list<int>  $retentionDays  Day offsets to compute
     * @return self
     */
    public function retentionCohort(array $retentionDays = [1, 3, 7]): self
    {
        $this->queryType = 'retention_cohort';
        $this->groupBy = 'cohort';
        $this->limit = count($retentionDays);

        return $this;
    }

    /**
     * Configure a SaaS dashboard summary query.
     *
     * @param  string  $currency  ISO 4217 currency code
     * @return self
     */
    public function saasDashboard(string $currency = 'USD'): self
    {
        $this->queryType = 'saas_dashboard';

        return $this;
    }

    /**
     * Execute the query against the EventQueryEngine.
     *
     * @return array<string, mixed>  Query results
     * @throws \RuntimeException If the query is not properly configured
     */
    public function execute(): array
    {
        $engine = app(EventQueryEngine::class);

        return match ($this->queryType) {
            'time_series' => $this->executeTimeSeries($engine),
            'funnel' => $this->executeFunnel($engine),
            'trending' => $this->executeTrending($engine),
            'conversion' => $this->executeConversion($engine),
            'category_breakdown' => $engine->categoryBreakdown($this->periodDays),
            'retention_cohort' => $this->executeRetentionCohort($engine),
            'saas_dashboard' => $engine->saasDashboardSummary('USD', $this->periodDays),
            default => throw new \ZeroBoiler\Analytics\Exceptions\AnalyticsRuntimeException("Unknown query type: {$this->queryType}"),
        };
    }

    /**
     * Execute and return only the data values (stripped of metadata).
     *
     * Useful for chart components that need just the numbers.
     *
     * @return array<string, mixed>
     */
    public function executeValues(): array
    {
        $result = $this->execute();

        return match ($this->queryType) {
            'time_series' => $result['series'] ?? $result,
            'funnel' => $result['steps'] ?? $result,
            'trending' => $result,
            'conversion' => [
                'rate' => $result['rate'] ?? 0.0,
                'from_count' => $result['from_count'] ?? 0,
                'to_count' => $result['to_count'] ?? 0,
            ],
            'category_breakdown' => $result['categories'] ?? $result,
            'saas_dashboard' => $result,
            default => $result,
        };
    }

    /**
     * Get the configured query type.
     *
     * @return string
     */
    public function getQueryType(): string
    {
        return $this->queryType;
    }

    /**
     * Get the configured events.
     *
     * @return list<string>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * Get the configured period.
     *
     * @return int
     */
    public function getPeriod(): int
    {
        return $this->periodDays;
    }

    /**
     * Serialize the query configuration to an array.
     *
     * Useful for caching query definitions.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $config = [
            'type' => $this->queryType,
            'events' => $this->events,
            'period_days' => $this->periodDays,
            'group_by' => $this->groupBy,
            'limit' => $this->limit,
        ];

        if ($this->funnelName !== null) {
            $config['funnel'] = $this->funnelName;
            $config['steps'] = $this->funnelSteps;
        }

        if ($this->conversionFrom !== '') {
            $config['conversion'] = [
                'from' => $this->conversionFrom,
                'to' => $this->conversionTo,
            ];
        }

        return $config;
    }

    /**
     * Create a builder from a serialized configuration array.
     *
     * @param  array<string, mixed>  $config  Serialized query config
     * @return self
     */
    public static function fromArray(array $config): self
    {
        $builder = new self();
        $builder->queryType = $config['type'] ?? 'time_series';
        $builder->events = $config['events'] ?? [];
        $builder->periodDays = $config['period_days'] ?? 7;
        $builder->groupBy = $config['group_by'] ?? 'day';
        $builder->limit = $config['limit'] ?? 10;
        $builder->funnelName = $config['funnel'] ?? null;
        $builder->funnelSteps = $config['steps'] ?? [];
        $builder->conversionFrom = $config['conversion']['from'] ?? '';
        $builder->conversionTo = $config['conversion']['to'] ?? '';

        return $builder;
    }

    // ── Private Execution Methods ───────────────────────────────

    /**
     * Execute a time-series query.
     *
     * @return array<string, mixed>
     */
    private function executeTimeSeries(EventQueryEngine $engine): array
    {
        if ($this->events === []) {
            throw new \ZeroBoiler\Analytics\Exceptions\AnalyticsRuntimeException('Time-series query requires at least one event. Use ->events() or ->event().');
        }

        return $engine->timeSeries($this->events, $this->periodDays);
    }

    /**
     * Execute a funnel query.
     *
     * @return array<string, mixed>
     */
    private function executeFunnel(EventQueryEngine $engine): array
    {
        if ($this->funnelSteps === []) {
            throw new \ZeroBoiler\Analytics\Exceptions\AnalyticsRuntimeException('Funnel query requires at least one step. Use ->steps() or ->funnel(name, steps).');
        }

        return $engine->funnelAnalysis(
            $this->funnelName ?? 'unnamed',
            $this->funnelSteps,
            $this->periodDays,
        );
    }

    /**
     * Execute a trending events query.
     *
     * @return array<string, mixed>
     */
    private function executeTrending(EventQueryEngine $engine): array
    {
        return $engine->trendingEvents($this->periodDays, $this->trendDirection, $this->limit);
    }

    /**
     * Execute a conversion rate query.
     *
     * @return array<string, mixed>
     */
    private function executeConversion(EventQueryEngine $engine): array
    {
        if ($this->conversionFrom === '' || $this->conversionTo === '') {
            throw new \ZeroBoiler\Analytics\Exceptions\AnalyticsRuntimeException('Conversion query requires from and to events. Use ->conversion(from, to).');
        }

        return $engine->conversionRate($this->conversionFrom, $this->conversionTo, $this->periodDays);
    }

    /**
     * Execute a retention cohort query.
     *
     * @return array<string, mixed>
     */
    private function executeRetentionCohort(EventQueryEngine $engine): array
    {
        $event = $this->events[0] ?? 'page_view';
        $cohortDays = min($this->periodDays, 14);
        $retentionDays = [1, 3, 7, 14];
        $retentionDays = array_filter($retentionDays, fn (int $d): bool => $d <= $cohortDays);

        return $engine->retentionCohort($event, $cohortDays, array_values($retentionDays));
    }
}
