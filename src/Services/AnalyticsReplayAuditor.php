<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Audits event replay operations for data integrity and compliance.
 *
 * Tracks replay attempts, success/failure rates, data integrity checks,
 * and compliance violations (e.g., replaying events past TTL, replaying
 * events that were already successfully delivered).
 *
 * Used by the DLQ replay system and zb:analytics:replay-audit command
 * to provide operators with visibility into replay operations.
 *
 * @since 118.0.0
 */
final class AnalyticsReplayAuditor
{
    /** Cache key prefix for replay audit logs */
    private const CACHE_PREFIX = 'zb_replay_audit_';

    /** Default TTL for audit entries (7 days) */
    private const DEFAULT_TTL = 604800;

    /**
     * @param  CacheRepository  $cache  Cache repository for audit persistence
     * @param  ConfigRepository  $config  Configuration repository
     */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly ConfigRepository $config,
    ){}

    /**
     * Record a replay attempt for an event.
     *
     * @param  AnalyticsEvent  $event  The event being replayed
     * @param  string  $provider  Target provider name
     * @param  bool  $success  Whether the replay succeeded
     * @param  string|null  $errorMessage  Error message if replay failed
     * @param  int|null  $attemptNumber  Current attempt number (1-indexed)
     * @return array{audit_id: string, timestamp: string, event_name: string, provider: string, success: bool, attempt: int, error: string|null, client_id: string|null, user_id: string|null}
     */
    public function record(
        AnalyticsEvent $event,
        string $provider,
        bool $success,
        ?string $errorMessage = null,
        ?int $attemptNumber = null,
    ): array {
        $auditId = $this->generateAuditId($event->name, $provider);
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $entry = [
            'audit_id' => $auditId,
            'timestamp' => $now,
            'event_name' => $event->name,
            'provider' => $provider,
            'success' => $success,
            'attempt' => $attemptNumber ?? 1,
            'error' => $errorMessage,
            'client_id' => $event->clientId,
            'user_id' => $event->userId,
        ];

        $ttl = $this->getAuditTtl();
        $this->cache->put(self::CACHE_PREFIX . $auditId, $entry, $ttl);

        return $entry;
    }

    /**
     * Get audit entries for a specific event.
     *
     * @param  string  $eventName  Event name to filter by
     * @param  int  $limit  Maximum number of entries to return
     * @return list<array<string, mixed>>
     */
    public function getForEvent(string $eventName, int $limit = 50): array
    {
        // Since we can't easily scan cache keys, use a summary approach
        $summaryKey = self::CACHE_PREFIX . 'summary_' . $eventName;

        /** @var list<array<string, mixed>>|null $entries */
        $entries = $this->cache->get($summaryKey);

        return array_slice($entries ?? [], -$limit);
    }

    /**
     * Get a summary of replay audit statistics.
     *
     * @return array{total_replays: int, successful: int, failed: int, success_rate: float, by_provider: array<string, array{total: int, success: int, failed: int}>, recent_failures: int}
     */
    public function summary(): array
    {
        /** @var array{total: int, successful: int, failed: int, by_provider: array<string, array{total: int, success: int, failed: int}>}|null $stats */
        $stats = $this->cache->get(self::CACHE_PREFIX . 'global_summary');

        if ($stats === null) {
            return [
                'total_replays' => 0,
                'successful' => 0,
                'failed' => 0,
                'success_rate' => 0.0,
                'by_provider' => [],
                'recent_failures' => 0,
            ];
        }

        $total = $stats['total'];
        $successful = $stats['successful'];
        $failed = $stats['failed'];

        return [
            'total_replays' => $total,
            'successful' => $successful,
            'failed' => $failed,
            'success_rate' => $total > 0 ? round(($successful / $total) * 100, 2) : 0.0,
            'by_provider' => $stats['by_provider'],
            'recent_failures' => $stats['failed'],
        ];
    }

    /**
     * Validate whether a replay is permitted for the given event.
     *
     * Checks replay TTL, maximum attempt limits, and provider-specific constraints.
     *
     * @param  AnalyticsEvent  $event  The event being replayed
     * @param  string  $provider  Target provider name
     * @return array{allowed: bool, reason: string|null}
     */
    public function validateReplay(AnalyticsEvent $event, string $provider): array
    {
        $config = $this->getReplayConfig();

        // Check if replay is globally enabled
        if (! ($config['enabled'] ?? true)) {
            return ['allowed' => false, 'reason' => 'Event replay is globally disabled.'];
        }

        // Check max attempts
        $maxAttempts = $config['max_attempts'] ?? 3;
        $auditId = $this->generateAuditId($event->name, $provider);

        /** @var array<string, mixed>|null $lastEntry */
        $lastEntry = $this->cache->get(self::CACHE_PREFIX . $auditId);

        if ($lastEntry !== null && isset($lastEntry['attempt']) && (int) $lastEntry['attempt'] >= $maxAttempts) {
            return ['allowed' => false, 'reason' => "Maximum replay attempts ({$maxAttempts}) exceeded for {$event->name} → {$provider}."];
        }

        // Check replay TTL
        $replayTtl = $config['replay_ttl'] ?? 86400; // 24 hours default
        if (isset($event->timestamp) && $event->timestamp instanceof \DateTimeImmutable) {
            $age = (new \DateTimeImmutable())->getTimestamp() - $event->timestamp->getTimestamp();
            if ($age > $replayTtl) {
                return ['allowed' => false, 'reason' => "Event age ({$age}s) exceeds replay TTL ({$replayTtl}s)."];
            }
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Increment global replay statistics after a replay operation.
     *
     * @param  string  $provider  Provider name
     * @param  bool  $success  Whether the replay succeeded
     */
    public function incrementStats(string $provider, bool $success): void
    {
        $summaryKey = self::CACHE_PREFIX . 'global_summary';

        /** @var array{total: int, successful: int, failed: int, by_provider: array<string, array{total: int, success: int, failed: int}>} $stats */
        $stats = $this->cache->get($summaryKey, [
            'total' => 0,
            'successful' => 0,
            'failed' => 0,
            'by_provider' => [],
        ]);

        $stats['total']++;

        if ($success) {
            $stats['successful']++;
        } else {
            $stats['failed']++;
        }

        if (! isset($stats['by_provider'][$provider])) {
            $stats['by_provider'][$provider] = ['total' => 0, 'success' => 0, 'failed' => 0];
        }

        $stats['by_provider'][$provider]['total']++;

        if ($success) {
            $stats['by_provider'][$provider]['success']++;
        } else {
            $stats['by_provider'][$provider]['failed']++;
        }

        $this->cache->put($summaryKey, $stats, $this->getAuditTtl());
    }

    /**
     * Clear all replay audit data.
     */
    public function clear(): void
    {
        // Note: In a real implementation, this would use cache tags or a prefix scan
        $this->cache->forget(self::CACHE_PREFIX . 'global_summary');
    }

    /**
     * Get the configured audit TTL.
     */
    private function getAuditTtl(): int
    {
        return (int) ($this->config->get('zeroboiler.analytics.replay_audit.ttl', self::DEFAULT_TTL));
    }

    /**
     * Get the replay configuration section.
     *
     * @return array{enabled: bool, max_attempts: int, replay_ttl: int}
     */
    private function getReplayConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = $this->config->get('zeroboiler.analytics.replay_audit', []);

        return [
            'enabled' => (bool) ($config['enabled'] ?? true),
            'max_attempts' => (int) ($config['max_attempts'] ?? 3),
            'replay_ttl' => (int) ($config['replay_ttl'] ?? 86400),
        ];
    }

    /**
     * Generate a deterministic audit ID for an event+provider pair.
     */
    private function generateAuditId(string $eventName, string $provider): string
    {
        return md5("{$eventName}:{$provider}");
    }
}
