<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;

/**
 * Eloquent model for persistent analytics event storage.
 *
 * Stores dispatched analytics events in the database for historical queries,
 * replay, GDPR compliance, data warehouse export, and audit trails.
 *
 * Designed for high-write throughput with indexed columns for common queries.
 * Supports automatic pruning via Laravel's Prunable trait for data retention.
 *
 * @property string $id UUID primary key
 * @property string $name Event name (e.g. 'purchase', 'page_view')
 * @property string|null $category Event category (ecommerce, saas, engagement, security, uptime)
 * @property array $params Event parameters (JSON)
 * @property string|null $user_id Authenticated user ID (nullable for anonymous)
 * @property string|null $client_id Server-generated client tracking ID
 * @property string|null $session_id Session identifier for grouping
 * @property string|null $provider Target provider (ga4, meta, posthog, etc.)
 * @property string|null $source Event source (client, server, lifecycle, api, batch)
 * @property string|null $ip Anonymized IP address
 * @property string|null $user_agent Browser user agent
 * @property string|null $url Page URL at time of event
 * @property string|null $referrer Referrer URL
 * @property string|null $consent_state Consent state at time of event
 * @property string|null $fingerprint Session fingerprint hash
 * @property int $priority Event priority (0=low, 1=normal, 2=high, 3=critical)
 * @property bool $dedup Whether event passed deduplication
 * @property string|null $idempotency_key Client-provided idempotency key
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @since 30.0.0
 */
final class AnalyticsEventModel extends Model
{
    use HasUuids;
    use Prunable;

    /**
     * The table associated with the model.
     */
    protected $table = 'analytics_events';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'category',
        'params',
        'user_id',
        'client_id',
        'session_id',
        'provider',
        'source',
        'ip',
        'user_agent',
        'url',
        'referrer',
        'consent_state',
        'fingerprint',
        'priority',
        'dedup',
        'idempotency_key',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'params' => 'array',
        'priority' => 'integer',
        'dedup' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the prunable model query.
     *
     * By default, prunes events older than 90 days.
     * Override via config('zeroboiler.analytics.event_store.retention_days').
     */
    public function prunable(): Builder
    {
        $retentionDays = (int) config('zeroboiler.analytics.event_store.retention_days', 90);

        return static::where('created_at', '<=', now()->subDays($retentionDays));
    }

    /**
     * Scope: filter by event name.
     */
    public function scopeByName(Builder $query, string $name): Builder
    {
        return $query->where('name', $name);
    }

    /**
     * Scope: filter by category.
     */
    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Scope: filter by user ID.
     */
    public function scopeByUserId(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: filter by client ID.
     */
    public function scopeByClientId(Builder $query, string $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope: filter by provider.
     */
    public function scopeByProvider(Builder $query, ?string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    /**
     * Scope: filter by date range (from).
     */
    public function scopeFrom(Builder $query, string $date): Builder
    {
        return $query->where('created_at', '>=', $date);
    }

    /**
     * Scope: filter by date range (to).
     */
    public function scopeTo(Builder $query, string $date): Builder
    {
        return $query->where('created_at', '<=', $date);
    }

    /**
     * Scope: filter by event source.
     */
    public function scopeBySource(Builder $query, string $source): Builder
    {
        return $query->where('source', $source);
    }

    /**
     * Scope: order by most recent first.
     */
    public function scopeLatest(Builder $query): Builder
    {
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Scope: filter by session ID.
     */
    public function scopeBySessionId(Builder $query, string $sessionId): Builder
    {
        return $query->where('session_id', $sessionId);
    }

    /**
     * Convert a database row to an AnalyticsEvent DTO.
     *
     * @return \ZeroBoiler\Analytics\DTO\AnalyticsEvent
     */
    public function toDto(): \ZeroBoiler\Analytics\DTO\AnalyticsEvent
    {
        return new \ZeroBoiler\Analytics\DTO\AnalyticsEvent(
            name: $this->name,
            params: $this->params ?? [],
            timestamp: $this->created_at?->toDateTimeString() ?? '',
        );
    }
}
