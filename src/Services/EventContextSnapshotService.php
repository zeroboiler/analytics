<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\EventContext;

/**
 * Point-in-time context snapshot service for analytics events.
 *
 * Captures device, session, geographic, and behavioral context snapshots
 * at event dispatch time. Snapshots are cached for replay, audit trails,
 * and post-hoc event enrichment. Supports GDPR-compliant PII stripping.
 *
 * Each snapshot includes: device fingerprint, session state, geographic hints,
 * behavioral score (events/minute), consent state, and a stable snapshot ID
 * for cross-reference in dashboards and compliance reports.
 *
 * @since 8.5.0
 */
final class EventContextSnapshotService
{
    /** @var CacheRepository */
    private CacheRepository $cache;

    private string $cachePrefix;

    private int $snapshotTtl;

    private int $maxSnapshotsPerClient;

    private bool $anonymizeIp;

    private int $ipMaskV4;

    private int $ipMaskV6;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config){
        $this->cache = $cache;

        $snapshotConfig = $config->get('zeroboiler.analytics.context_snapshot', []);
        /** @var array{cache_prefix?: string, snapshot_ttl?: int, max_snapshots_per_client?: int} $snapshotConfig */

        $this->cachePrefix = (string) ($snapshotConfig['cache_prefix'] ?? 'zb_ctx_snapshot_');
        $this->snapshotTtl = (int) ($snapshotConfig['snapshot_ttl'] ?? 86400); // 24 hours
        $this->maxSnapshotsPerClient = (int) ($snapshotConfig['max_snapshots_per_client'] ?? 100);

        $gdprConfig = $config->get('zeroboiler.analytics.gdpr', []);
        /** @var array{anonymize_ip?: bool, ip_mask_v4?: int, ip_mask_v6?: int} $gdprConfig */

        $this->anonymizeIp = (bool) ($gdprConfig['anonymize_ip'] ?? false);
        $this->ipMaskV4 = (int) ($gdprConfig['ip_mask_v4'] ?? 2);
        $this->ipMaskV6 = (int) ($gdprConfig['ip_mask_v6'] ?? 48);
    }

    /**
     * Capture a point-in-time context snapshot for an event.
     *
     * Creates an immutable snapshot of the current request context, enriches
     * it with behavioral metrics, and caches it for later retrieval.
     *
     * @param  EventContext  $context  Resolved event context
     * @param  string  $eventName  Name of the event being dispatched
     * @param  int|null  $eventsThisMinute  Current events/minute for behavioral scoring
     * @return array{snapshot_id: string, event_name: string, captured_at: string, device: array<string, mixed>, session: array<string, mixed>, geographic: array<string, mixed>, behavioral: array<string, mixed>, consent: array<string, mixed>, client_id: string|null, user_id: string|null}
     */
    public function capture(
        EventContext $context,
        string $eventName,
        ?int $eventsThisMinute = null,
    ): array {
        $snapshotId = $this->generateSnapshotId($eventName);

        $snapshot = [
            'snapshot_id' => $snapshotId,
            'event_name' => $eventName,
            'captured_at' => now()->toIso8601String(),
            'device' => $this->captureDeviceSnapshot($context),
            'session' => $this->captureSessionSnapshot($context),
            'geographic' => $this->captureGeographicSnapshot($context),
            'behavioral' => $this->captureBehavioralSnapshot($eventsThisMinute),
            'consent' => $this->captureConsentSnapshot($context),
            'client_id' => $context->clientId,
            'user_id' => $context->userId,
        ];

        // Cache snapshot for replay
        if ($context->clientId !== null) {
            $this->cacheSnapshotForClient($context->clientId, $snapshotId, $snapshot);
        }

        // Cache the snapshot by its own ID for direct lookup
        try {
            $this->cache->put(
                $this->cachePrefix . 'snapshot_' . $snapshotId,
                $snapshot,
                $this->snapshotTtl,
            );
        } catch (\Throwable $e) {
            // Cache unavailable
        }

        return $snapshot;
    }

    /**
     * Retrieve a cached snapshot by its ID.
     *
     * @param  string  $snapshotId
     * @return array<string, mixed>|null
     */
    public function getSnapshot(string $snapshotId): ?array
    {
        try {
            $snapshot = $this->cache->get($this->cachePrefix . 'snapshot_' . $snapshotId);

            return is_array($snapshot) ? $snapshot : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Retrieve all cached snapshots for a client ID.
     *
     * @param  string  $clientId
     * @return list<array<string, mixed>>
     */
    public function getClientSnapshots(string $clientId): array
    {
        try {
            $snapshotIds = $this->cache->get($this->cachePrefix . 'client_' . $clientId);

            if (! is_array($snapshotIds)) {
                return [];
            }

            $snapshots = [];
            foreach (array_slice($snapshotIds, -50) as $id) {
                $snapshot = $this->getSnapshot((string) $id);
                if ($snapshot !== null) {
                    $snapshots[] = $snapshot;
                }
            }

            return $snapshots;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Generate a replay payload from a snapshot for event re-dispatch.
     *
     * Creates a minimal event parameter array from a cached snapshot,
     * suitable for re-dispatching through the analytics pipeline.
     *
     * @param  string  $snapshotId
     * @return array<string, mixed>|null
     */
    public function replayFromSnapshot(string $snapshotId): ?array
    {
        $snapshot = $this->getSnapshot($snapshotId);

        if ($snapshot === null) {
            return null;
        }

        return [
            'event_name' => $snapshot['event_name'],
            'client_id' => $snapshot['client_id'],
            'user_id' => $snapshot['user_id'],
            'device_type' => $snapshot['device']['type'] ?? null,
            'device_browser' => $snapshot['device']['browser'] ?? null,
            'device_os' => $snapshot['device']['os'] ?? null,
            'page_path' => $snapshot['session']['path'] ?? null,
            'page_referrer' => $snapshot['session']['referrer'] ?? null,
            'locale' => $snapshot['geographic']['locale'] ?? null,
            'country' => $snapshot['geographic']['country'] ?? null,
            '_replayed_from' => $snapshotId,
            '_original_captured_at' => $snapshot['captured_at'],
        ];
    }

    /**
     * Get snapshot statistics.
     *
     * @return array{total_cached_snapshots: int, clients_with_snapshots: int, cache_prefix: string, ttl: int}
     */
    public function stats(): array
    {
        return [
            'total_cached_snapshots' => 0, // Cannot enumerate without scanning
            'clients_with_snapshots' => 0,
            'cache_prefix' => $this->cachePrefix,
            'ttl' => $this->snapshotTtl,
        ];
    }

    /**
     * Delete all snapshots for a specific client ID (GDPR erasure).
     *
     * @param  string  $clientId
     * @return int Number of snapshots deleted
     */
    public function forgetClientSnapshots(string $clientId): int
    {
        try {
            $snapshotIds = $this->cache->get($this->cachePrefix . 'client_' . $clientId);

            if (! is_array($snapshotIds)) {
                return 0;
            }

            foreach ($snapshotIds as $id) {
                $this->cache->forget($this->cachePrefix . 'snapshot_' . (string) $id);
            }

            $this->cache->forget($this->cachePrefix . 'client_' . $clientId);

            return count($snapshotIds);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Capture device context snapshot.
     *
     * @param  EventContext  $context
     * @return array{type: string|null, browser: string|null, os: string|null, user_agent: string|null, fingerprint: string}
     */
    private function captureDeviceSnapshot(EventContext $context): array
    {
        $deviceType = $context->device['type'] ?? null;
        $browser = $context->device['browser'] ?? null;
        $os = $context->device['os'] ?? null;
        $userAgent = $context->device['user_agent'] ?? $context->userAgent;

        return [
            'type' => $deviceType,
            'browser' => $browser,
            'os' => $os,
            'user_agent' => $userAgent !== null ? $this->truncateUserAgent($userAgent) : null,
            'fingerprint' => $this->deviceFingerprint($deviceType, $browser, $os),
        ];
    }

    /**
     * Capture session context snapshot.
     *
     * @param  EventContext  $context
     * @return array{session_id: string|null, path: string|null, referrer: string|null, url: string|null, method: string|null}
     */
    private function captureSessionSnapshot(EventContext $context): array
    {
        return [
            'session_id' => $context->sessionId !== null
                ? $this->hashId($context->sessionId)
                : null,
            'path' => $context->path,
            'referrer' => $context->referrer,
            'url' => $context->url,
            'method' => $context->method,
        ];
    }

    /**
     * Capture geographic context snapshot.
     *
     * @param  EventContext  $context
     * @return array{country: string|null, locale: string|null, ip: string|null, ip_anonymized: string|null}
     */
    private function captureGeographicSnapshot(EventContext $context): array
    {
        $rawIp = $context->ip;

        return [
            'country' => $context->country,
            'locale' => $context->locale,
            'ip' => $rawIp,
            'ip_anonymized' => $this->anonymizeIp && $rawIp !== null
                ? $this->anonymizeIpAddress($rawIp)
                : null,
        ];
    }

    /**
     * Capture behavioral context snapshot.
     *
     * @param  int|null  $eventsThisMinute
     * @return array{events_per_minute: int|null, velocity_score: string, engagement_signal: string}
     */
    private function captureBehavioralSnapshot(?int $eventsThisMinute): array
    {
        $velocity = $this->calculateVelocityScore($eventsThisMinute ?? 0);

        return [
            'events_per_minute' => $eventsThisMinute,
            'velocity_score' => $velocity,
            'engagement_signal' => $this->engagementSignal($eventsThisMinute ?? 0),
        ];
    }

    /**
     * Capture consent context snapshot.
     *
     * @param  EventContext  $context
     * @return array{granted: bool, has_user: bool, has_client: bool, has_utm: bool}
     */
    private function captureConsentSnapshot(EventContext $context): array
    {
        return [
            'granted' => $context->consentGranted,
            'has_user' => $context->hasUser(),
            'has_client' => $context->hasClientId(),
            'has_utm' => $context->hasUtm(),
        ];
    }

    /**
     * Generate a stable snapshot ID from event name and timestamp.
     *
     * @param  string  $eventName
     * @return string
     */
    private function generateSnapshotId(string $eventName): string
    {
        $prefix = substr(md5($eventName), 0, 8);

        return $prefix . '_' . (string) (hrtime(true) % 1_000_000_000_000);
    }

    /**
     * Cache a snapshot ID for a client, maintaining FIFO limit.
     *
     * @param  string  $clientId
     * @param  string  $snapshotId
     * @param  array<string, mixed>  $snapshot
     */
    private function cacheSnapshotForClient(string $clientId, string $snapshotId, array $snapshot): void
    {
        try {
            $existing = $this->cache->get($this->cachePrefix . 'client_' . $clientId);

            if (! is_array($existing)) {
                $existing = [];
            }

            if (count($existing) >= $this->maxSnapshotsPerClient) {
                // Remove oldest entries (FIFO)
                $existing = array_slice($existing, -(int) floor($this->maxSnapshotsPerClient * 0.8));
            }

            $existing[] = $snapshotId;

            $this->cache->put(
                $this->cachePrefix . 'client_' . $clientId,
                $existing,
                $this->snapshotTtl,
            );
        } catch (\Throwable $e) {
            // Cache unavailable
        }
    }

    /**
     * Anonymize an IP address according to GDPR settings.
     *
     * @param  string  $ip
     * @return string
     */
    private function anonymizeIpAddress(string $ip): string
    {
        if (str_contains($ip, ':')) {
            // IPv6: mask to configured bits
            return $this->maskIpv6($ip, $this->ipMaskV6);
        }

        // IPv4: mask to configured octets
        return $this->maskIpv4($ip, $this->ipMaskV4);
    }

    /**
     * Mask an IPv4 address preserving the first N octets.
     *
     * @param  string  $ip
     * @param  int  $octets
     * @return string
     */
    private function maskIpv4(string $ip, int $octets): string
    {
        $parts = explode('.', $ip);

        if (count($parts) !== 4) {
            return '0.0.0.0';
        }

        for ($i = $octets; $i < 4; $i++) {
            $parts[$i] = '0';
        }

        return implode('.', $parts);
    }

    /**
     * Mask an IPv6 address preserving the first N bits.
     *
     * @param  string  $ip
     * @param  int  $bits
     * @return string
     */
    private function maskIpv6(string $ip, int $bits): string
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return '::';
        }

        $bytes = strlen($packed);
        $maskBytes = (int) floor($bits / 8);

        $masked = str_repeat("\0", $bytes);

        for ($i = 0; $i < $maskBytes && $i < $bytes; $i++) {
            $masked[$i] = $packed[$i];
        }

        // Partial byte masking
        $remainingBits = $bits % 8;
        if ($remainingBits > 0 && $maskBytes < $bytes) {
            $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
            $masked[$maskBytes] = chr(ord($packed[$maskBytes]) & $mask);
        }

        return inet_ntop($masked) ?: '::';
    }

    /**
     * Calculate velocity score from events per minute.
     *
     * @param  int  $eventsPerMinute
     * @return string 'low', 'normal', 'elevated', 'high'
     */
    private function calculateVelocityScore(int $eventsPerMinute): string
    {
        return match (true) {
            $eventsPerMinute <= 2 => 'low',
            $eventsPerMinute <= 10 => 'normal',
            $eventsPerMinute <= 30 => 'elevated',
            default => 'high',
        };
    }

    /**
     * Determine engagement signal from event velocity.
     *
     * @param  int  $eventsPerMinute
     * @return string 'passive', 'active', 'engaged', 'power_user'
     */
    private function engagementSignal(int $eventsPerMinute): string
    {
        return match (true) {
            $eventsPerMinute === 0 => 'passive',
            $eventsPerMinute <= 3 => 'active',
            $eventsPerMinute <= 15 => 'engaged',
            default => 'power_user',
        };
    }

    /**
     * Generate a stable device fingerprint from type, browser, and OS.
     *
     * @param  string|null  $type
     * @param  string|null  $browser
     * @param  string|null  $os
     * @return string
     */
    private function deviceFingerprint(?string $type, ?string $browser, ?string $os): string
    {
        $raw = implode('|', array_filter([$type, $browser, $os]));

        return $raw !== '' ? substr(md5($raw), 0, 12) : 'unknown';
    }

    /**
     * Truncate user agent for storage efficiency.
     *
     * @param  string  $userAgent
     * @return string
     */
    private function truncateUserAgent(string $userAgent): string
    {
        return strlen($userAgent) > 200
            ? substr($userAgent, 0, 200) . '...'
            : $userAgent;
    }

    /**
     * Hash an ID for privacy-safe storage.
     *
     * @param  string  $id
     * @return string
     */
    private function hashId(string $id): string
    {
        return substr(hash('sha256', $id), 0, 16);
    }
}
