<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
/**
 * SDK Token Audit Logger — Tracks all SDK token lifecycle events for security auditing.
 *
 * Maintains a time-bounded, cache-backed audit log of all SDK token operations:
 * generation, validation, revocation, rate limit hits, and origin violations.
 * Designed for GDPR Article 30 compliance and security incident investigation.
 *
 * Each audit entry contains:
 * - Token scope name (human-readable)
 * - Operation type (generate, validate, revoke, rate_limited, origin_blocked, environment_blocked)
 * - IP address of the requesting client
 * - User agent
 * - Timestamp
 * - Outcome (success, failure, blocked)
 * - Additional context (permission checked, event category, etc.)
 *
 * Audit entries are automatically pruned based on configurable TTL.
 * Supports exporting audit logs for compliance reports.
 *
 * Configuration: `zeroboiler.analytics.sdk_tokens.audit`
 *
 * @see \ZeroBoiler\Analytics\Services\SdkScopeTokenService
 *
 * @since 156.0.0
 */
final class SdkTokenAuditLogger
{
    /** @var string Cache key for the audit log */
    private const CACHE_KEY = 'zb_sdk_token_audit_log';

    /** @var string Cache key for audit counters (per-operation aggregation) */
    private const COUNTERS_KEY = 'zb_sdk_token_audit_counters';

    /** @var int Maximum audit entries to keep in cache */
    private const MAX_ENTRIES = 1000;

    /** Operation types */
    public const OP_GENERATE = 'generate';
    public const OP_VALIDATE = 'validate';
    public const OP_VALIDATE_FAIL = 'validate_fail';
    public const OP_REVOKE = 'revoke';
    public const OP_RATE_LIMITED = 'rate_limited';
    public const OP_ORIGIN_BLOCKED = 'origin_blocked';
    public const OP_ENVIRONMENT_BLOCKED = 'environment_blocked';
    public const OP_PERMISSION_DENIED = 'permission_denied';
    public const OP_ROTATE = 'rotate';
    public const OP_LIST = 'list';
    public const OP_STATS = 'stats';

    /** Outcome types */
    public const OUTCOME_SUCCESS = 'success';
    public const OUTCOME_FAILURE = 'failure';
    public const OUTCOME_BLOCKED = 'blocked';

    /** @var list<string> All valid operation types */
    private const ALL_OPERATIONS = [
        self::OP_GENERATE,
        self::OP_VALIDATE,
        self::OP_VALIDATE_FAIL,
        self::OP_REVOKE,
        self::OP_RATE_LIMITED,
        self::OP_ORIGIN_BLOCKED,
        self::OP_ENVIRONMENT_BLOCKED,
        self::OP_PERMISSION_DENIED,
        self::OP_ROTATE,
        self::OP_LIST,
        self::OP_STATS,
    ];

    private CacheRepository $cache;

    private bool $enabled;

    private int $ttl;

    /** @var int Max entries to retain */
    private int $maxEntries;

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  ConfigRepository  $config  Config repository
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;

        $auditConfig = $config->get('zeroboiler.analytics.sdk_tokens.audit', []);
        /** @var array{enabled?: bool, ttl?: int, max_entries?: int} $auditConfig */

        $this->enabled = (bool) ($auditConfig['enabled'] ?? true);
        $this->ttl = (int) ($auditConfig['ttl'] ?? 604800); // 7 days
        $this->maxEntries = (int) ($auditConfig['max_entries'] ?? self::MAX_ENTRIES);
    }

    /**
     * Check if audit logging is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Record an audit log entry.
     *
     * @param  string  $operation  Operation type (one of OP_* constants)
     * @param  string  $scope  Token scope name
     * @param  string  $ip  Client IP address
     * @param  string  $userAgent  Client user agent
     * @param  string  $outcome  Outcome (success, failure, blocked)
     * @param  array<string, mixed>  $context  Additional context data
     */
    public function log(
        string $operation,
        string $scope,
        string $ip,
        string $userAgent,
        string $outcome,
        array $context = [],
    ): void {
        if (! $this->enabled) {
            return;
        }

        if (! in_array($operation, self::ALL_OPERATIONS, true)) {
            return; // Invalid operation — silently skip to avoid disrupting analytics pipeline
        }

        $entry = [
            'id' => uniqid('za_', true),
            'operation' => $operation,
            'scope' => $scope,
            'ip' => $this->hashIp($ip),
            'user_agent' => $this->truncate($userAgent, 200),
            'outcome' => $outcome,
            'context' => $context,
            'timestamp' => time(),
        ];

        $log = $this->cache->get(self::CACHE_KEY, []);

        if (! is_array($log)) {
            $log = [];
        }

        $log[] = $entry;

        // Prune old entries if exceeding max
        if (count($log) > $this->maxEntries) {
            $log = array_slice($log, -$this->maxEntries);
        }

        $this->cache->put(self::CACHE_KEY, $log, $this->ttl);

        $this->incrementCounter($operation, $outcome);
    }

    /**
     * Get all audit log entries.
     *
     * @param  int  $limit  Max entries to return (0 = all)
     * @param  string|null  $operation  Filter by operation type
     * @param  string|null  $scope  Filter by scope name
     * @return list<array{id: string, operation: string, scope: string, ip: string, user_agent: string, outcome: string, context: array<string, mixed>, timestamp: int}>
     */
    public function getEntries(int $limit = 100, ?string $operation = null, ?string $scope = null): array
    {
        /** @var list<array{id: string, operation: string, scope: string, ip: string, user_agent: string, outcome: string, context: array<string, mixed>, timestamp: int}> $log */
        $log = $this->cache->get(self::CACHE_KEY, []);

        if (! is_array($log)) {
            return [];
        }

        if ($operation !== null) {
            $log = array_values(array_filter(
                $log,
                static fn(array $entry): bool => $entry['operation'] === $operation,
            ));
        }

        if ($scope !== null) {
            $log = array_values(array_filter(
                $log,
                static fn(array $entry): bool => $entry['scope'] === $scope,
            ));
        }

        $log = array_reverse($log);

        if ($limit > 0) {
            $log = array_slice($log, 0, $limit);
        }

        return $log;
    }

    /**
     * Get aggregated audit counters.
     *
     * @return array{total: int, by_operation: array<string, int>, by_outcome: array<string, int>, rate_limited_last_hour: int, blocked_last_hour: int}
     */
    public function getStats(): array
    {
        /** @var array{total?: int, by_operation?: array<string, int>, by_outcome?: array<string, int>, rate_limited_last_hour?: int, blocked_last_hour?: int} $counters */
        $counters = $this->cache->get(self::COUNTERS_KEY, []);

        return [
            'total' => (int) ($counters['total'] ?? 0),
            'by_operation' => (array) ($counters['by_operation'] ?? []),
            'by_outcome' => (array) ($counters['by_outcome'] ?? []),
            'rate_limited_last_hour' => (int) ($counters['rate_limited_last_hour'] ?? 0),
            'blocked_last_hour' => (int) ($counters['blocked_last_hour'] ?? 0),
        ];
    }

    /**
     * Clear all audit logs and counters.
     */
    public function clear(): void
    {
        $this->cache->forget(self::CACHE_KEY);
        $this->cache->forget(self::COUNTERS_KEY);
    }

    /**
     * Get the number of audit entries currently stored.
     */
    public function count(): int
    {
        /** @var list<mixed> $log */
        $log = $this->cache->get(self::CACHE_KEY, []);

        return is_array($log) ? count($log) : 0;
    }

    /**
     * Get recent security events (rate limits, blocks, denials).
     *
     * @return list<array{id: string, operation: string, scope: string, ip: string, user_agent: string, outcome: string, context: array<string, mixed>, timestamp: int}>
     */
    public function getSecurityEvents(int $limit = 50): array
    {
        $securityOps = [
            self::OP_RATE_LIMITED,
            self::OP_ORIGIN_BLOCKED,
            self::OP_ENVIRONMENT_BLOCKED,
            self::OP_PERMISSION_DENIED,
            self::OP_VALIDATE_FAIL,
        ];

        /** @var list<array{id: string, operation: string, scope: string, ip: string, user_agent: string, outcome: string, context: array<string, mixed>, timestamp: int}> $log */
        $log = $this->cache->get(self::CACHE_KEY, []);

        if (! is_array($log)) {
            return [];
        }

        $events = array_values(array_filter(
            $log,
            static fn(array $entry): bool => in_array($entry['operation'], $securityOps, true),
        ));

        return array_slice(array_reverse($events), 0, $limit);
    }

    /**
     * Hash an IP address for GDPR-safe storage.
     * Uses the first 3 octets + mask for IPv4 to enable anomaly detection
     * without storing personally-identifiable full IPs.
     */
    private function hashIp(string $ip): string
    {
        if ($ip === '' || $ip === 'unknown' || $ip === '::1' || $ip === '127.0.0.1') {
            return 'local';
        }

        // IPv4: mask last octet for partial anonymity
        if (str_contains($ip, '.')) {
            $parts = explode('.', $ip);

            if (count($parts) === 4) {
                return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.xxx';
            }
        }

        // IPv6: take first 3 segments
        if (str_contains($ip, ':')) {
            $parts = explode(':', $ip);

            if (count($parts) >= 3) {
                return $parts[0] . ':' . $parts[1] . ':' . $parts[2] . '::';
            }
        }

        // Fallback: hash the IP
        return substr(hash('sha256', $ip), 0, 12);
    }

    /**
     * Truncate a string to a maximum length.
     */
    private function truncate(string $value, int $maxLength): string
    {
        return mb_strlen($value) > $maxLength
            ? mb_substr($value, 0, $maxLength) . '...'
            : $value;
    }

    /**
     * Increment aggregated counters.
     *
     * @param  string  $operation  Operation type
     * @param  string  $outcome  Outcome type
     */
    private function incrementCounter(string $operation, string $outcome): void
    {
        /** @var array{total?: int, by_operation?: array<string, int>, by_outcome?: array<string, int>, rate_limited_last_hour?: int, blocked_last_hour?: int} $counters */
        $counters = $this->cache->get(self::COUNTERS_KEY, []);

        if (! is_array($counters)) {
            $counters = [];
        }

        $counters['total'] = ($counters['total'] ?? 0) + 1;

        if (! isset($counters['by_operation'])) {
            $counters['by_operation'] = [];
        }
        $counters['by_operation'][$operation] = ($counters['by_operation'][$operation] ?? 0) + 1;

        if (! isset($counters['by_outcome'])) {
            $counters['by_outcome'] = [];
        }
        $counters['by_outcome'][$outcome] = ($counters['by_outcome'][$outcome] ?? 0) + 1;

        // Hourly security counters (auto-reset by TTL)
        if ($operation === self::OP_RATE_LIMITED) {
            $counters['rate_limited_last_hour'] = ($counters['rate_limited_last_hour'] ?? 0) + 1;
        }

        if (in_array($operation, [self::OP_ORIGIN_BLOCKED, self::OP_ENVIRONMENT_BLOCKED, self::OP_PERMISSION_DENIED], true)) {
            $counters['blocked_last_hour'] = ($counters['blocked_last_hour'] ?? 0) + 1;
        }

        $this->cache->put(self::COUNTERS_KEY, $counters, 3600); // 1 hour TTL for counters
    }

    /**
     * Get all valid operation types.
     *
     * @return list<string>
     */
    public static function allOperations(): array
    {
        return self::ALL_OPERATIONS;
    }
}
