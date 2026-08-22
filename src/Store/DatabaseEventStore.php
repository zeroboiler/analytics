<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Store;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use ZeroBoiler\Analytics\Contracts\AnalyticsEventStoreInterface;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Models\AnalyticsEventModel;

/**
 * Database-backed event store using Eloquent.
 *
 * Provides persistent storage for analytics events in the configured
 * database connection. Optimized for high-write throughput with bulk inserts
 * and indexed query columns.
 *
 * This is the recommended store for production SaaS applications that need:
 * - Historical event queries and reporting
 * - Event replay capabilities
 * - GDPR data erasure compliance
 * - Data warehouse export
 * - Audit trails
 *
 * @since 30.0.0
 */
final class DatabaseEventStore implements AnalyticsEventStoreInterface
{
    /**
     * Create a new database event store instance.
     */
    public function __construct(
        private readonly string $connection = 'mysql',
        private readonly string $table = 'analytics_events',
    ) {}

    /**
     * {@inheritdoc}
     */
    public function store(AnalyticsEvent $event): ?string
    {
        try {
            $model = AnalyticsEventModel::on($this->connection)->create(
                $this->mapToModelAttributes($event),
            );

            return $model->id;
        } catch (\Throwable $e) {
            Log::warning('ZeroBoiler: Failed to persist event to database', [
                'event' => $event->name,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function storeBatch(array $events): array
    {
        if ($events === []) {
            return [];
        }

        $ids = [];

        try {
            $rows = array_map(
                fn(AnalyticsEvent $event) => $this->mapToModelAttributes($event),
                $events,
            );

            // Chunk inserts to avoid MySQL max_allowed_packet limits
            $chunks = array_chunk($rows, 500);

            foreach ($chunks as $chunk) {
                DB::connection($this->connection)
                    ->table($this->table)
                    ->insert($chunk);

                foreach ($chunk as $row) {
                    $ids[] = $row['id'] ?? null;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('ZeroBoiler: Failed to persist event batch to database', [
                'count' => count($events),
                'error' => $e->getMessage(),
            ]);

            // Fallback: try individual inserts
            foreach ($events as $event) {
                $id = $this->store($event);
                if ($id !== null) {
                    $ids[] = $id;
                }
            }
        }

        return array_filter($ids);
    }

    /**
     * {@inheritdoc}
     */
    public function retrieve(string $id): ?AnalyticsEvent
    {
        $model = AnalyticsEventModel::on($this->connection)->find($id);

        if ($model === null) {
            return null;
        }

        return $model->toDto();
    }

    /**
     * {@inheritdoc}
     */
    public function query(array $filters = []): array
    {
        $query = AnalyticsEventModel::on($this->connection)->newQuery();
        $this->applyFilters($query, $filters);

        $limit = $filters['limit'] ?? 100;
        $offset = $filters['offset'] ?? 0;
        $sort = $filters['sort'] ?? 'created_at';
        $direction = $filters['direction'] ?? 'desc';

        // Validate sort column to prevent SQL injection
        $allowedSorts = ['created_at', 'name', 'category', 'provider', 'priority'];
        $safeSort = in_array($sort, $allowedSorts, true) ? $sort : 'created_at';
        $safeDirection = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        $models = $query
            ->orderBy($safeSort, $safeDirection)
            ->skip($offset)
            ->take(min($limit, 1000))
            ->get();

        return $models->map(fn(AnalyticsEventModel $m) => $m->toDto())->all();
    }

    /**
     * {@inheritdoc}
     */
    public function count(array $filters = []): int
    {
        $query = AnalyticsEventModel::on($this->connection)->newQuery();
        $this->applyFilters($query, $filters);

        return $query->count();
    }

    /**
     * {@inheritdoc}
     */
    public function delete(array $filters = []): int
    {
        $query = AnalyticsEventModel::on($this->connection)->newQuery();
        $this->applyFilters($query, $filters);

        return $query->delete();
    }

    /**
     * {@inheritdoc}
     */
    public function deleteById(string $id): bool
    {
        $model = AnalyticsEventModel::on($this->connection)->find($id);

        if ($model === null) {
            return false;
        }

        $model->delete();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function purge(): bool
    {
        try {
            DB::connection($this->connection)
                ->table($this->table)
                ->truncate();

            return true;
        } catch (\Throwable $e) {
            Log::error('ZeroBoiler: Failed to purge event store', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function aggregateBy(string $groupBy, array $filters = []): array
    {
        $query = AnalyticsEventModel::on($this->connection)->newQuery();
        $this->applyFilters($query, $filters);

        $column = match ($groupBy) {
            'event_name' => 'name',
            'category' => 'category',
            'provider' => 'provider',
            'source' => 'source',
            'user_id' => 'user_id',
            'client_id' => 'client_id',
            'hour' => DB::raw("DATE_FORMAT(created_at, '%Y-%m-%d %H:00')"),
            'day' => DB::raw("DATE(created_at)"),
            'week' => DB::raw("DATE_FORMAT(created_at, '%x-W%v')"),
            'month' => DB::raw("DATE_FORMAT(created_at, '%Y-%m')"),
            'priority' => 'priority',
            default => 'name',
        };

        $results = $query
            ->select([$column, DB::raw('COUNT(*) as aggregate')])
            ->groupBy(DB::raw("({$column})"))
            ->orderByDesc('aggregate')
            ->limit(100)
            ->get();

        /** @var array<string, int> */
        $mapped = [];

        foreach ($results as $row) {
            $key = (string) ($row->{$groupBy === 'event_name' ? 'name' : $groupBy} ?? 'unknown');
            $mapped[$key] = (int) $row->aggregate;
        }

        return $mapped;
    }

    /**
     * {@inheritdoc}
     */
    public function isHealthy(): bool
    {
        try {
            DB::connection($this->connection)
                ->table($this->table)
                ->select(1)
                ->limit(1)
                ->get();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Map an AnalyticsEvent DTO to model attributes for database storage.
     *
     * Extracts available properties from the DTO. Since AnalyticsEvent is readonly,
     * we map only the properties that exist on the DTO and use params for
     * additional metadata (category, session_id, provider, etc.) that may be
     * embedded in event params by enrichers.
     *
     * @param  AnalyticsEvent  $event
     * @return array<string, mixed>
     */
    private function mapToModelAttributes(AnalyticsEvent $event): array
    {
        $params = $event->params;

        // Map priority string to integer
        $priorityInt = match ($event->priority) {
            'critical' => 3,
            'high' => 2,
            'normal' => 1,
            'low' => 0,
            'background' => 0,
            default => 1,
        };

        // These are commonly injected by pipeline enrichers
        $category = is_string($params['_category'] ?? null) ? $params['_category'] : null;
        $sessionId = is_string($params['_session_id'] ?? null) ? $params['_session_id'] : null;
        $provider = is_string($params['_provider'] ?? null) ? $params['_provider'] : null;
        $ip = is_string($params['_ip'] ?? null) ? $params['_ip'] : null;
        $userAgent = is_string($params['_user_agent'] ?? null) ? $params['_user_agent'] : null;
        $url = is_string($params['_url'] ?? null) ? $params['_url'] : null;
        $referrer = is_string($params['_referrer'] ?? null) ? $params['_referrer'] : null;
        $consentState = is_string($params['_consent_state'] ?? null) ? $params['_consent_state'] : null;
        $fingerprint = is_string($params['_fingerprint'] ?? null) ? $params['_fingerprint'] : null;
        $idempotencyKey = is_string($params['_idempotency_key'] ?? null) ? $params['_idempotency_key'] : null;
        $dedup = ($params['_dedup'] ?? true) === true;

        // Strip internal params from stored payload to avoid duplication
        $cleanParams = $params;
        foreach (['_category', '_session_id', '_provider', '_ip', '_user_agent', '_url', '_referrer', '_consent_state', '_fingerprint', '_idempotency_key', '_dedup'] as $internalKey) {
            unset($cleanParams[$internalKey]);
        }

        return [
            'id' => (string) Str::uuid(),
            'name' => $event->name,
            'category' => $category,
            'params' => $cleanParams,
            'user_id' => $event->userId,
            'client_id' => $event->clientId,
            'session_id' => $sessionId,
            'provider' => $provider,
            'source' => $event->source,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'url' => $url,
            'referrer' => $referrer,
            'consent_state' => $consentState,
            'fingerprint' => $fingerprint,
            'priority' => $priorityInt,
            'dedup' => $dedup,
            'idempotency_key' => $idempotencyKey,
            'created_at' => $event->timestamp ?? now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Apply query filters to an Eloquent builder.
     *
     * @param  Builder<AnalyticsEventModel>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (isset($filters['event_name'])) {
            $query->where('name', $filters['event_name']);
        }

        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (array_key_exists('provider', $filters)) {
            $query->where('provider', $filters['provider']);
        }

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['client_id'])) {
            $query->where('client_id', $filters['client_id']);
        }

        if (isset($filters['session_id'])) {
            $query->where('session_id', $filters['session_id']);
        }

        if (isset($filters['source'])) {
            $query->where('source', $filters['source']);
        }

        if (isset($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        if (isset($filters['fingerprint'])) {
            $query->where('fingerprint', $filters['fingerprint']);
        }

        if (isset($filters['name_like'])) {
            $query->where('name', 'LIKE', $filters['name_like']);
        }
    }
}
