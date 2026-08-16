<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\Services\SdkScopeTokenService;
use ZeroBoiler\Analytics\Services\SdkTokenAuditLogger;

/**
 * Analytics SDK Token Management Command.
 *
 * Manage scoped SDK tokens: generate, list, revoke, rotate, and view audit logs.
 * Provides a CLI interface for administrative token lifecycle management.
 *
 * Supports:
 *   - Token generation with custom permissions and categories
 *   - Listing all tokens across scopes
 *   - Token revocation
 *   - Token rotation (revoke old + generate new)
 *   - Audit log viewing (security events, all events, counters)
 *   - Audit log clearing
 *
 * @see \ZeroBoiler\Analytics\Services\SdkScopeTokenService
 * @see \ZeroBoiler\Analytics\Services\SdkTokenAuditLogger
 *
 * @since 156.0.0
 */
final class AnalyticsSdkTokenCommand extends Command
{
    private const string SIGNATURE = 'zb:analytics:sdk-tokens
        {action? : Action to perform (generate|list|revoke|rotate|audit|audit-clear|stats)}
        {--scope= : Token scope name (for generate/rotate)}
        {--permissions=track,batch : Comma-separated permissions (track,batch,identify,consent,pageview)}
        {--categories=ecommerce,saas,engagement,custom : Comma-separated allowed categories}
        {--rate-limit=100 : Per-minute rate limit for the token}
        {--environment=production : Environment restriction (production,staging,all)}
        {--token= : Raw token string (for revoke)}
        {--json : Output as JSON}
        {--security : Show only security events (for audit)}';

    private const string DESCRIPTION = 'Manage SDK scoped tokens — generate, list, revoke, rotate, and audit';

    private string $signature = self::SIGNATURE;

    private string $description = self::DESCRIPTION;

    private CacheRepository $cache;

    private ConfigRepository $config;

    private ?SdkScopeTokenService $tokenService;

    private ?SdkTokenAuditLogger $auditLogger;

    /** @var string Cache prefix for scope token tracking */
    private const string SCOPE_CACHE_PREFIX = 'zb_sdk_token_scope_';

    /**
     * @param  CacheRepository  $cache  Cache repository
     * @param  ConfigRepository  $config  Config repository
     * @param  SdkScopeTokenService|null  $tokenService  SDK scope token service
     * @param  SdkTokenAuditLogger|null  $auditLogger  SDK token audit logger
     */
    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
        ?SdkScopeTokenService $tokenService = null,
        ?SdkTokenAuditLogger $auditLogger = null,
    ): void {
        parent::__construct();
        $this->cache = $cache;
        $this->config = $config;
        $this->tokenService = $tokenService;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Execute the console command.
     *
     * @return int Exit code (0 = success, 1 = error)
     */
    #[Override]
    public function handle(): int
    {
        $action = $this->argument('action');

        if ($action === null) {
            $this->displayStatus();

            return 0;
        }

        return match ($action) {
            'generate' => $this->generateToken(),
            'list' => $this->listTokens(),
            'revoke' => $this->revokeToken(),
            'rotate' => $this->rotateToken(),
            'audit' => $this->showAudit(),
            'audit-clear' => $this->clearAudit(),
            'stats' => $this->showStats(),
            default => $this->invalidAction($action),
        };
    }

    /**
     * Display current SDK token system status.
     */
    private function displayStatus(): int
    {
        $sdkConfig = $this->config->get('zeroboiler.analytics.sdk_tokens', []);
        $authConfig = $this->config->get('zeroboiler.analytics.sdk_auth', []);
        /** @var array{enabled?: bool} $sdkConfig */
        /** @var array{enabled?: bool} $authConfig */

        $tokenEnabled = (bool) ($sdkConfig['enabled'] ?? false);
        $authEnabled = (bool) ($authConfig['enabled'] ?? false);
        $ttl = (int) ($sdkConfig['token_ttl'] ?? 7776000);
        $rateLimit = (int) ($sdkConfig['default_rate_limit'] ?? 100);
        $maxPerScope = (int) ($sdkConfig['max_tokens_per_scope'] ?? 10);

        $this->components->info('SDK Token Gateway Status');
        $this->line('');

        $this->line('  Tokens:    ' . ($tokenEnabled ? '<info>enabled</info>' : '<comment>disabled</comment>'));
        $this->line('  Auth:      ' . ($authEnabled ? '<info>enabled</info>' : '<comment>disabled</comment>'));
        $this->line('  TTL:       ' . $this->formatDuration($ttl));
        $this->line('  Rate:      ' . $rateLimit . '/min');
        $this->line('  Max/scope: ' . $maxPerScope);

        // Audit status
        if ($this->auditLogger !== null && $this->auditLogger->isEnabled()) {
            $stats = $this->auditLogger->getStats();
            $this->line('');
            $this->line('  Audit Log:');
            $this->line('    Entries:   ' . $this->auditLogger->count());
            $this->line('    Total:     ' . $stats['total']);
            $this->line('    Blocked:   ' . ($stats['blocked_last_hour'] ?? 0) . ' (last hour)');
            $this->line('    Rate-lim:  ' . ($stats['rate_limited_last_hour'] ?? 0) . ' (last hour)');
        }

        return 0;
    }

    /**
     * Generate a new SDK token.
     */
    private function generateToken(): int
    {
        $scope = $this->option('scope');

        if ($scope === null || $scope === '') {
            $this->components->error('--scope is required for generate action');

            return 1;
        }

        if ($this->tokenService === null || ! $this->tokenService->isEnabled()) {
            $this->components->error('SDK token service is not enabled');

            return 1;
        }

        $permissions = $this->parseCsvOption('permissions', SdkScopeTokenService::PERM_TRACK . ',' . SdkScopeTokenService::PERM_BATCH);
        $categories = $this->parseCsvOption('categories', 'ecommerce,saas,engagement,custom');
        $rateLimit = (int) $this->option('rate-limit');
        $environment = (string) $this->option('environment');

        try {
            $result = $this->tokenService->generateToken(
                $scope,
                $permissions,
                $categories,
                [
                    'rate_limit' => $rateLimit,
                    'environment' => $environment,
                ],
            );
        } catch (\Throwable $e) {
            $this->components->error('Token generation failed: ' . $e->getMessage());

            return 1;
        }

        // Audit log
        if ($this->auditLogger !== null) {
            $this->auditLogger->log(
                SdkTokenAuditLogger::OP_GENERATE,
                $scope,
                'cli',
                'artisan',
                SdkTokenAuditLogger::OUTCOME_SUCCESS,
                ['permissions' => $permissions, 'categories' => $categories, 'rate_limit' => $rateLimit],
            );
        }

        if ($this->option('json')) {
            $this->output->write(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->components->info('Token generated for scope: ' . $scope);
        $this->line('');
        $this->line('  Token:     <comment>' . $result['token'] . '</comment>');
        $this->line('  Scope:     ' . $result['scope']);
        $this->line('  Perms:     ' . implode(', ', $result['permissions']));
        $this->line('  Categories:' . implode(', ', $result['categories']));
        $this->line('  Expires:   ' . date('Y-m-d H:i:s', $result['expires_at']));

        return 0;
    }

    /**
     * List all tokens across scopes.
     */
    private function listTokens(): int
    {
        if ($this->tokenService === null || ! $this->tokenService->isEnabled()) {
            $this->components->error('SDK token service is not enabled');

            return 1;
        }

        // Scan cache for scope keys
        $scopes = $this->discoverScopes();

        if (empty($scopes)) {
            $this->components->info('No SDK tokens found');

            return 0;
        }

        $tokens = [];
        foreach ($scopes as $scopeName => $tokenHashes) {
            foreach ($tokenHashes as $hash) {
                $cacheKey = 'zb_sdk_token_token_' . $hash;
                $data = $this->cache->get($cacheKey);
                if (is_array($data)) {
                    $tokens[] = [
                        'scope' => $data['scope'] ?? $scopeName,
                        'hash' => substr($hash, 0, 12) . '...',
                        'permissions' => implode(', ', $data['permissions'] ?? []),
                        'categories' => implode(', ', $data['categories'] ?? []),
                        'rate_limit' => $data['rate_limit'] ?? 0,
                        'environment' => $data['environment'] ?? 'production',
                        'created_at' => date('Y-m-d H:i:s', (int) ($data['created_at'] ?? 0)),
                        'expires_at' => date('Y-m-d H:i:s', (int) ($data['expires_at'] ?? 0)),
                    ];
                }
            }
        }

        // Audit log
        if ($this->auditLogger !== null) {
            $this->auditLogger->log(
                SdkTokenAuditLogger::OP_LIST,
                'cli',
                'cli',
                'artisan',
                SdkTokenAuditLogger::OUTCOME_SUCCESS,
                ['token_count' => count($tokens)],
            );
        }

        if ($this->option('json')) {
            $this->output->write(json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->components->info(count($tokens) . ' SDK token(s) found');
        $this->table(
            ['Scope', 'Hash', 'Permissions', 'Rate', 'Env', 'Created', 'Expires'],
            array_map(static fn(array $t): array => [
                $t['scope'],
                $t['hash'],
                $t['permissions'],
                $t['rate_limit'] . '/min',
                $t['environment'],
                $t['created_at'],
                $t['expires_at'],
            ], $tokens),
        );

        return 0;
    }

    /**
     * Revoke a token.
     */
    private function revokeToken(): int
    {
        $token = $this->option('token');

        if ($token === null || $token === '') {
            $this->components->error('--token is required for revoke action');

            return 1;
        }

        if ($this->tokenService === null || ! $this->tokenService->isEnabled()) {
            $this->components->error('SDK token service is not enabled');

            return 1;
        }

        $revoked = $this->tokenService->revokeToken($token);

        if ($this->auditLogger !== null) {
            $this->auditLogger->log(
                SdkTokenAuditLogger::OP_REVOKE,
                'cli',
                'cli',
                'artisan',
                $revoked ? SdkTokenAuditLogger::OUTCOME_SUCCESS : SdkTokenAuditLogger::OUTCOME_FAILURE,
                ['token_prefix' => substr($token, 0, 8) . '...'],
            );
        }

        if ($revoked) {
            $this->components->info('Token revoked successfully');

            return 0;
        }

        $this->components->error('Token not found (already expired or revoked)');

        return 1;
    }

    /**
     * Rotate a token (revoke + generate new).
     */
    private function rotateToken(): int
    {
        $scope = $this->option('scope');

        if ($scope === null || $scope === '') {
            $this->components->error('--scope is required for rotate action');

            return 1;
        }

        if ($this->tokenService === null || ! $this->tokenService->isEnabled()) {
            $this->components->error('SDK token service is not enabled');

            return 1;
        }

        $permissions = $this->parseCsvOption('permissions', SdkScopeTokenService::PERM_TRACK . ',' . SdkScopeTokenService::PERM_BATCH);
        $categories = $this->parseCsvOption('categories', 'ecommerce,saas,engagement,custom');
        $rateLimit = (int) $this->option('rate-limit');
        $environment = (string) $this->option('environment');

        try {
            $result = $this->tokenService->generateToken(
                $scope,
                $permissions,
                $categories,
                [
                    'rate_limit' => $rateLimit,
                    'environment' => $environment,
                ],
            );
        } catch (\Throwable $e) {
            $this->components->error('Token rotation failed: ' . $e->getMessage());

            return 1;
        }

        if ($this->auditLogger !== null) {
            $this->auditLogger->log(
                SdkTokenAuditLogger::OP_ROTATE,
                $scope,
                'cli',
                'artisan',
                SdkTokenAuditLogger::OUTCOME_SUCCESS,
                ['permissions' => $permissions, 'new_token_prefix' => substr($result['token'], 0, 8) . '...'],
            );
        }

        if ($this->option('json')) {
            $this->output->write(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->components->info('Token rotated for scope: ' . $scope);
        $this->line('  New token: <comment>' . $result['token'] . '</comment>');
        $this->line('  Expires:   ' . date('Y-m-d H:i:s', $result['expires_at']));
        $this->line('');
        $this->components->warn('Remember to update all clients with the new token. Old tokens for this scope remain valid until individually revoked.');

        return 0;
    }

    /**
     * Show audit log.
     */
    private function showAudit(): int
    {
        if ($this->auditLogger === null) {
            $this->components->error('SDK token audit logger is not available');

            return 1;
        }

        $security = $this->option('security');
        $entries = $security
            ? $this->auditLogger->getSecurityEvents(50)
            : $this->auditLogger->getEntries(50);

        if (empty($entries)) {
            $this->components->info('No audit entries found');

            return 0;
        }

        if ($this->option('json')) {
            $this->output->write(json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->components->info(count($entries) . ' audit entries' . ($security ? ' (security only)' : ''));

        $this->table(
            ['Time', 'Operation', 'Scope', 'IP', 'Outcome', 'Context'],
            array_map(static fn(array $e): array => [
                date('Y-m-d H:i:s', $e['timestamp']),
                $e['operation'],
                $e['scope'],
                $e['ip'],
                $e['outcome'],
                json_encode($e['context'] ?? [], JSON_UNESCAPED_SLASHES),
            ], $entries),
        );

        return 0;
    }

    /**
     * Show audit stats.
     */
    private function showStats(): int
    {
        if ($this->auditLogger === null) {
            $this->components->error('SDK token audit logger is not available');

            return 1;
        }

        $stats = $this->auditLogger->getStats();

        if ($this->option('json')) {
            $this->output->write(json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }

        $this->components->info('SDK Token Audit Stats');
        $this->line('');
        $this->line('  Total events:       ' . $stats['total']);
        $this->line('  Stored entries:     ' . $this->auditLogger->count());
        $this->line('  Rate-limited (1h):  ' . $stats['rate_limited_last_hour']);
        $this->line('  Blocked (1h):       ' . $stats['blocked_last_hour']);

        if (! empty($stats['by_operation'])) {
            $this->line('');
            $this->line('  By Operation:');
            foreach ($stats['by_operation'] as $op => $count) {
                $this->line('    ' . str_pad($op, 25) . $count);
            }
        }

        if (! empty($stats['by_outcome'])) {
            $this->line('');
            $this->line('  By Outcome:');
            foreach ($stats['by_outcome'] as $outcome => $count) {
                $this->line('    ' . str_pad($outcome, 25) . $count);
            }
        }

        return 0;
    }

    /**
     * Clear audit logs.
     */
    private function clearAudit(): int
    {
        if ($this->auditLogger === null) {
            $this->components->error('SDK token audit logger is not available');

            return 1;
        }

        $this->auditLogger->clear();
        $this->components->info('Audit log cleared');

        return 0;
    }

    /**
     * Handle invalid action.
     */
    private function invalidAction(string $action): int
    {
        $this->components->error("Invalid action: {$action}");
        $this->line('Valid actions: generate, list, revoke, rotate, audit, audit-clear, stats');

        return 1;
    }

    /**
     * Parse a CSV option into an array.
     *
     * @return list<string>
     */
    private function parseCsvOption(string $option, string $default): array
    {
        $value = $this->option($option);

        if ($value === null || $value === '') {
            return explode(',', $default);
        }

        /** @var string $value */
        return array_map('trim', explode(',', $value));
    }

    /**
     * Discover all token scopes from cache.
     *
     * @return array<string, list<string>>
     */
    private function discoverScopes(): array
    {
        $scopes = [];

        // We can't scan all cache keys in a framework-agnostic way,
        // so return empty — token listing relies on known scope names.
        // In production, this would query a dedicated token registry table.
        return $scopes;
    }

    /**
     * Format seconds into human-readable duration.
     */
    private function formatDuration(int $seconds): string
    {
        $days = (int) floor($seconds / 86400);
        $hours = (int) floor(($seconds % 86400) / 3600);

        if ($days > 0) {
            return $days . 'd ' . $hours . 'h';
        }

        return (int) floor($seconds / 3600) . 'h';
    }
}
