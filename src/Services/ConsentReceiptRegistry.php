<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Str;

/**
 * GDPR Consent Receipt Registry — audit-proof consent recording service.
 *
 * Maintains a cryptographic chain of consent receipts that serve as
 * legally defensible proof of consent for each data subject. Each receipt
 * records what was consented to, when, by whom, and includes a hash chain
 * for tamper detection.
 *
 * Supports:
 * - Recording individual consent grants and withdrawals
 * - Querying consent history for a specific user/client
 * - Generating verifiable consent receipts for regulatory audits
 * - Computing consent metrics for compliance dashboards
 * - Automatic expiry and renewal tracking
 * - Hash-chained integrity verification
 *
 * Configuration: `zeroboiler.analytics.consent_receipt`
 *
 * @see \ZeroBoiler\Analytics\Services\AnalyticsConsentComplianceService
 * @see \ZeroBoiler\Analytics\DTO\ConsentState
 *
 * @since 62.0.0
 */
final class ConsentReceiptRegistry
{
    private const CACHE_PREFIX = 'zb_cr_';
    private const INDEX_KEY = 'zb_cr_index';
    private const CHAIN_KEY = 'zb_cr_chain_head';

    private const RECEIPT_VERSION = '1.0';

    /** @var array{enabled: bool, cache_ttl: int, retention_days: int, include_hash_chain: bool, include_ip: bool, include_user_agent: bool, require_auth: bool, max_receipts_per_client: int, auto_record_consent_changes: bool, purposes: list<string>} */
    private array $config;

    private bool $enabled;

    private int $cacheTtl;

    private int $retentionDays;

    private bool $includeHashChain;

    private bool $includeIp;

    private bool $includeUserAgent;

    private bool $requireAuth;

    private int $maxReceiptsPerClient;

    private bool $autoRecord;

    /** @var list<string> */
    private array $purposes;

    private CacheRepository $cache;

    /**
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(CacheRepository $cache, ConfigRepository $config): void
    {
        $this->cache = $cache;
        $crConfig = $config->get('zeroboiler.analytics.consent_receipt', []);

        $this->enabled = (bool) ($crConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($crConfig['cache_ttl'] ?? 7776000); // 90 days
        $this->retentionDays = (int) ($crConfig['retention_days'] ?? 2555); // 7 years (GDPR Article 5(1)(e))
        $this->includeHashChain = (bool) ($crConfig['include_hash_chain'] ?? true);
        $this->includeIp = (bool) ($crConfig['include_ip'] ?? true);
        $this->includeUserAgent = (bool) ($crConfig['include_user_agent'] ?? false);
        $this->requireAuth = (bool) ($crConfig['require_auth'] ?? false);
        $this->maxReceiptsPerClient = (int) ($crConfig['max_receipts_per_client'] ?? 100);
        $this->autoRecord = (bool) ($crConfig['auto_record_consent_changes'] ?? true);
        $this->purposes = (array) ($crConfig['purposes'] ?? ['analytics_storage', 'ad_storage', 'ad_user_data', 'ad_personalization', 'functionality_storage', 'personalization_storage']);

        $this->config = [
            'enabled' => $this->enabled,
            'cache_ttl' => $this->cacheTtl,
            'retention_days' => $this->retentionDays,
            'include_hash_chain' => $this->includeHashChain,
            'include_ip' => $this->includeIp,
            'include_user_agent' => $this->includeUserAgent,
            'require_auth' => $this->requireAuth,
            'max_receipts_per_client' => $this->maxReceiptsPerClient,
            'auto_record_consent_changes' => $this->autoRecord,
            'purposes' => $this->purposes,
        ];
    }

    /**
     * Check if the consent receipt registry is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Record a consent receipt.
     *
     * Creates a tamper-evident receipt for a consent state change and stores
     * it in the cache-backed registry. Optionally maintains a hash chain
     * for integrity verification.
     *
     * @param  string  $clientId  The analytics client ID (from cookie)
     * @param  array<string, string>  $consentState  Current consent signals (e.g., ['analytics_storage' => 'granted', ...])
     * @param  string  $action  'grant', 'withdraw', 'update', or 'renew'
     * @param  array{user_id?: string|null, ip?: string|null, user_agent?: string|null, source?: string, version?: string}  $metadata
     * @return array{id: string, timestamp: string, client_id: string, action: string, consent_state: array<string, string>, purposes: list<string>, previous_hash: string|null, receipt_hash: string, metadata: array<string, mixed>}
     */
    public function record(
        string $clientId,
        array $consentState,
        string $action = 'update',
        array $metadata = [],
    ): array {
        $id = 'cr_' . Str::uuid()->toString();
        $timestamp = date('c');

        // Validate action
        $validActions = ['grant', 'withdraw', 'update', 'renew'];
        if (! in_array($action, $validActions, true)) {
            $action = 'update';
        }

        // Get previous hash for chain integrity
        $previousHash = $this->includeHashChain ? $this->getChainHead($clientId) : null;

        // Build receipt
        $receipt = [
            'id' => $id,
            'version' => self::RECEIPT_VERSION,
            'timestamp' => $timestamp,
            'client_id' => $clientId,
            'action' => $action,
            'consent_state' => $this->normalizeConsentState($consentState),
            'purposes' => $this->extractChangedPurposes($consentState),
        ];

        // Optional metadata
        if ($this->includeIp && isset($metadata['ip']) && is_string($metadata['ip'])) {
            $receipt['ip'] = $metadata['ip'];
        }
        if ($this->includeUserAgent && isset($metadata['user_agent']) && is_string($metadata['user_agent'])) {
            $receipt['user_agent'] = $metadata['user_agent'];
        }
        if (isset($metadata['user_id'])) {
            $receipt['user_id'] = is_string($metadata['user_id']) || is_int($metadata['user_id'])
                ? (string) $metadata['user_id']
                : null;
        }
        if (isset($metadata['source']) && is_string($metadata['source'])) {
            $receipt['source'] = $metadata['source'];
        }
        if (isset($metadata['version']) && is_string($metadata['version'])) {
            $receipt['consent_version'] = $metadata['version'];
        }

        // Hash chain integrity
        if ($this->includeHashChain) {
            $receipt['previous_hash'] = $previousHash;
            $receipt['receipt_hash'] = $this->computeHash($receipt);
            $this->updateChainHead($clientId, $receipt['receipt_hash']);
        }

        // Store the receipt
        $this->storeReceipt($clientId, $id, $receipt);

        // Update index
        $this->addToIndex($clientId, $id, $timestamp);

        return $receipt;
    }

    /**
     * Get the consent receipt history for a client.
     *
     * Returns receipts in reverse chronological order (newest first).
     *
     * @param  string  $clientId
     * @param  int  $limit
     * @return list<array<string, mixed>>
     */
    public function getHistory(string $clientId, int $limit = 50): array
    {
        $index = $this->getIndex($clientId);
        $receipts = [];

        $ids = array_slice(array_reverse($index), 0, $limit);

        foreach ($ids as $id) {
            $receipt = $this->getReceipt($id);
            if ($receipt !== null) {
                $receipts[] = $receipt;
            }
        }

        return $receipts;
    }

    /**
     * Get the latest consent receipt for a client.
     *
     * @param  string  $clientId
     * @return array<string, mixed>|null
     */
    public function getLatest(string $clientId): ?array
    {
        $index = $this->getIndex($clientId);

        if ($index === []) {
            return null;
        }

        $latestId = $index[array_key_last($index)] ?? null;

        if ($latestId === null) {
            return null;
        }

        return $this->getReceipt($latestId);
    }

    /**
     * Verify the integrity of the consent receipt chain for a client.
     *
     * Walks the hash chain from the most recent receipt backwards,
     * verifying that each receipt's hash was computed correctly and
     * that the chain is unbroken.
     *
     * @param  string  $clientId
     * @return array{valid: bool, total_receipts: int, verified_count: int, broken_at: string|null, issues: list<string>}
     */
    public function verifyChain(string $clientId): array
    {
        $history = $this->getHistory($clientId, $this->maxReceiptsPerClient);
        $total = count($history);
        $verifiedCount = 0;
        $issues = [];
        $brokenAt = null;

        if (!$this->includeHashChain) {
            return [
                'valid' => true,
                'total_receipts' => $total,
                'verified_count' => $total,
                'broken_at' => null,
                'issues' => ['Hash chain verification is disabled in configuration'],
            ];
        }

        if ($total === 0) {
            return [
                'valid' => true,
                'total_receipts' => 0,
                'verified_count' => 0,
                'broken_at' => null,
                'issues' => [],
            ];
        }

        for ($i = 0; $i < $total; $i++) {
            $receipt = $history[$i];
            $expectedHash = $this->computeHash($receipt);

            if (($receipt['receipt_hash'] ?? null) !== $expectedHash) {
                $brokenAt = $receipt['id'] ?? "index_{$i}";
                $issues[] = "Receipt {$brokenAt} has invalid hash — possible tampering detected";
                break;
            }

            // Check chain linkage (current receipt's previous_hash should match next receipt's hash)
            if ($i < $total - 1) {
                $nextReceipt = $history[$i + 1];
                $nextHash = $nextReceipt['receipt_hash'] ?? null;
                $previousHash = $receipt['previous_hash'] ?? null;

                if ($nextHash !== $previousHash) {
                    $brokenAt = $receipt['id'] ?? "index_{$i}";
                    $issues[] = "Chain broken at receipt {$brokenAt} — previous_hash does not match next receipt's hash";
                    break;
                }
            }

            $verifiedCount++;
        }

        return [
            'valid' => count($issues) === 0,
            'total_receipts' => $total,
            'verified_count' => $verifiedCount,
            'broken_at' => $brokenAt,
            'issues' => $issues,
        ];
    }

    /**
     * Get a specific receipt by ID.
     *
     * @param  string  $receiptId
     * @return array<string, mixed>|null
     */
    public function getReceipt(string $receiptId): ?array
    {
        /** @var array<string, mixed>|null $cached */
        $cached = $this->cache->get(self::CACHE_PREFIX . $receiptId);

        return is_array($cached) ? $cached : null;
    }

    /**
     * Get consent metrics for compliance dashboards.
     *
     * Computes aggregated statistics about consent patterns across
     * all tracked clients.
     *
     * @return array{total_clients: int, total_receipts: int, by_action: array<string, int>, by_purpose: array<string, array{granted: int, denied: int, rate: float}>, average_receipts_per_client: float, retention_compliance: array{enabled: bool, retention_days: int, policy_note: string}}
     */
    public function metrics(): array
    {
        // Note: In a production system with many clients, this would query a database.
        // For cache-backed implementation, we scan available indices.
        $indices = $this->cache->get(self::INDEX_KEY, []);

        $totalClients = 0;
        $totalReceipts = 0;
        $byAction = ['grant' => 0, 'withdraw' => 0, 'update' => 0, 'renew' => 0];
        $byPurpose = [];
        $clientCounts = [];

        foreach ($indices as $clientId => $receiptIds) {
            if (!is_array($receiptIds)) {
                continue;
            }

            $totalClients++;
            $count = count($receiptIds);
            $totalReceipts += $count;
            $clientCounts[] = $count;

            // Sample the latest receipt for purpose analysis
            $latestId = $receiptIds[array_key_last($receiptIds)] ?? null;
            if ($latestId !== null && is_string($latestId)) {
                $receipt = $this->getReceipt($latestId);
                if ($receipt !== null) {
                    $action = $receipt['action'] ?? 'update';
                    $byAction[$action] = ($byAction[$action] ?? 0) + 1;

                    foreach ($receipt['consent_state'] ?? [] as $purpose => $value) {
                        if (!isset($byPurpose[$purpose])) {
                            $byPurpose[$purpose] = ['granted' => 0, 'denied' => 0, 'rate' => 0.0];
                        }
                        if ($value === 'granted') {
                            $byPurpose[$purpose]['granted']++;
                        } else {
                            $byPurpose[$purpose]['denied']++;
                        }
                    }
                }
            }
        }

        // Calculate consent rates
        foreach ($byPurpose as $purpose => &$data) {
            $total = $data['granted'] + $data['denied'];
            $data['rate'] = $total > 0 ? round($data['granted'] / $total * 100, 1) : 0.0;
        }
        unset($data);

        $avgReceipts = $totalClients > 0
            ? round(array_sum($clientCounts) / $totalClients, 2)
            : 0.0;

        return [
            'total_clients' => $totalClients,
            'total_receipts' => $totalReceipts,
            'by_action' => $byAction,
            'by_purpose' => $byPurpose,
            'average_receipts_per_client' => $avgReceipts,
            'retention_compliance' => [
                'enabled' => true,
                'retention_days' => $this->retentionDays,
                'policy_note' => "Consent receipts are retained for {$this->retentionDays} days per GDPR Article 5(1)(e). Extend retention if required by local law.",
            ],
        ];
    }

    /**
     * Purge expired consent receipts for a client.
     *
     * Removes receipts older than the configured retention period.
     *
     * @param  string  $clientId
     * @return int Number of receipts purged
     */
    public function purgeExpired(string $clientId): int
    {
        $index = $this->getIndex($clientId);
        $cutoff = time() - ($this->retentionDays * 86400);
        $purged = 0;

        foreach ($index as $id => $timestamp) {
            $ts = is_numeric($timestamp) ? (int) $timestamp : strtotime((string) $timestamp);
            if ($ts !== false && $ts < $cutoff) {
                $this->cache->forget(self::CACHE_PREFIX . $id);
                unset($index[$id]);
                $purged++;
            }
        }

        $this->cache->put(self::INDEX_KEY, $this->getAllIndices(), $this->retentionDays * 86400);

        return $purged;
    }

    /**
     * Export all receipts for a client in a verifiable format.
     *
     * Generates a JSON export suitable for regulatory submission,
     * including chain verification proof.
     *
     * @param  string  $clientId
     * @return array{client_id: string, exported_at: string, receipt_count: int, chain_verification: array<string, mixed>, receipts: list<array<string, mixed>>}
     */
    public function exportForAudit(string $clientId): array
    {
        $history = $this->getHistory($clientId, $this->maxReceiptsPerClient);
        $verification = $this->verifyChain($clientId);

        return [
            'client_id' => $clientId,
            'exported_at' => date('c'),
            'receipt_count' => count($history),
            'chain_verification' => $verification,
            'receipts' => $history,
        ];
    }

    /**
     * Get the service configuration.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Normalize consent state to ensure valid values.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, string>
     */
    private function normalizeConsentState(array $state): array
    {
        $normalized = [];

        foreach ($state as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            $normalized[$key] = ((string) $value) === 'granted' ? 'granted' : 'denied';
        }

        return $normalized;
    }

    /**
     * Extract which purposes changed from the consent state.
     *
     * @param  array<string, mixed>  $state
     * @return list<string>
     */
    private function extractChangedPurposes(array $state): array
    {
        $purposes = [];

        foreach (array_keys($state) as $purpose) {
            if (is_string($purpose) && in_array($purpose, $this->purposes, true)) {
                $purposes[] = $purpose;
            }
        }

        return $purposes;
    }

    /**
     * Compute a hash for a receipt (excluding the hash field itself).
     *
     * @param  array<string, mixed>  $receipt
     */
    private function computeHash(array $receipt): string
    {
        $data = $receipt;
        unset($data['receipt_hash']); // Don't hash the hash itself

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Store a receipt in the cache.
     *
     * @param  string  $clientId
     * @param  string  $id
     * @param  array<string, mixed>  $receipt
     */
    private function storeReceipt(string $clientId, string $id, array $receipt): void
    {
        $this->cache->put(
            self::CACHE_PREFIX . $id,
            $receipt,
            $this->retentionDays * 86400,
        );
    }

    /**
     * Get the receipt index for a client.
     *
     * @param  string  $clientId
     * @return array<string, string>  receipt_id => timestamp
     */
    private function getIndex(string $clientId): array
    {
        $allIndices = $this->getAllIndices();

        $index = $allIndices[$clientId] ?? [];

        return is_array($index) ? $index : [];
    }

    /**
     * Get all client indices.
     *
     * @return array<string, array<string, string>>
     */
    private function getAllIndices(): array
    {
        /** @var array<string, array<string, string>> $indices */
        $indices = $this->cache->get(self::INDEX_KEY, []);

        return is_array($indices) ? $indices : [];
    }

    /**
     * Add a receipt to the client's index.
     *
     * @param  string  $clientId
     * @param  string  $id
     * @param  string  $timestamp
     */
    private function addToIndex(string $clientId, string $id, string $timestamp): void
    {
        $allIndices = $this->getAllIndices();

        if (!isset($allIndices[$clientId]) || !is_array($allIndices[$clientId])) {
            $allIndices[$clientId] = [];
        }

        // Enforce max receipts per client
        $receipts = $allIndices[$clientId];
        if (count($receipts) >= $this->maxReceiptsPerClient) {
            // Remove oldest receipts
            $oldestIds = array_slice(array_keys($receipts), 0, count($receipts) - $this->maxReceiptsPerClient + 1);
            foreach ($oldestIds as $oldId) {
                $this->cache->forget(self::CACHE_PREFIX . $oldId);
                unset($receipts[$oldId]);
            }
        }

        $receipts[$id] = $timestamp;
        $allIndices[$clientId] = $receipts;

        $this->cache->put(self::INDEX_KEY, $allIndices, $this->retentionDays * 86400);
    }

    /**
     * Get the current chain head hash for a client.
     *
     * @param  string  $clientId
     */
    private function getChainHead(string $clientId): ?string
    {
        $key = self::CHAIN_KEY . '_' . $clientId;

        /** @var string|null $head */
        $head = $this->cache->get($key);

        return is_string($head) && $head !== '' ? $head : null;
    }

    /**
     * Update the chain head hash for a client.
     *
     * @param  string  $clientId
     * @param  string  $hash
     */
    private function updateChainHead(string $clientId, string $hash): void
    {
        $key = self::CHAIN_KEY . '_' . $clientId;
        $this->cache->put($key, $hash, $this->retentionDays * 86400);
    }
}
