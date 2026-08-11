<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Cross-domain analytics tracking service for multi-domain SaaS apps.
 *
 * Enables unified visitor tracking across multiple domains (e.g., app.example.com
 * + docs.example.com + blog.example.com) by linking client IDs via a shared
 * authentication or link parameter mechanism.
 *
 * Supports three linking strategies:
 * - `linker_param`: Auto-generates URL decoration with _zbclid parameter
 * - `auth_link`: Links client IDs when the same authenticated user visits
 *   different domains
 * - `post_message`: Browser-based cross-origin messaging (via JS client)
 *
 * @since 9.8.0
 */
final class CrossDomainTrackingService
{
    /** @var list<string> */
    private array $domains;

    private bool $enabled;

    private string $linkerParam;

    private string $cachePrefix;

    private int $linkTtl;

    private bool $autoLinkerEnabled;

    /** @var list<string> */
    private array $excludedDomains;

    /**
     * @param  ConfigRepository  $config
     */
    public function __construct(ConfigRepository $config)
    {
        $crossDomain = $config->get('zeroboiler.analytics.cross_domain', []);
        /** @var array{enabled?: bool, domains?: list<string>, linker_param?: string, auto_linker?: bool, cache_prefix?: string, link_ttl?: int, excluded_domains?: list<string>} $crossDomain */

        $this->enabled = (bool) ($crossDomain['enabled'] ?? false);
        $this->domains = (array) ($crossDomain['domains'] ?? []);
        $this->linkerParam = (string) ($crossDomain['linker_param'] ?? '_zbclid');
        $this->autoLinkerEnabled = (bool) ($crossDomain['auto_linker'] ?? true);
        $this->cachePrefix = (string) ($crossDomain['cache_prefix'] ?? 'zb_crossdomain_');
        $this->linkTtl = (int) ($crossDomain['link_ttl'] ?? 900); // 15 minutes
        $this->excludedDomains = (array) ($crossDomain['excluded_domains'] ?? []);
    }

    /**
     * Check if cross-domain tracking is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled && count($this->domains) > 1;
    }

    /**
     * Get all configured domains.
     *
     * @return list<string>
     */
    public function getDomains(): array
    {
        return $this->domains;
    }

    /**
     * Get the linker parameter name.
     */
    public function getLinkerParam(): string
    {
        return $this->linkerParam;
    }

    /**
     * Check if a domain is part of the cross-domain tracking configuration.
     */
    public function isTrackedDomain(string $domain): bool
    {
        // Normalize: remove protocol and port
        $normalized = strtolower(preg_replace('#^https?://([^/]+).*$#', '$1', $domain) ?? $domain);

        return in_array($normalized, $this->excludedDomains, true)
            ? false
            : $this->domainMatches($normalized);
    }

    /**
     * Extract a client ID from the linker parameter in a URL.
     *
     * Parses the _zbclid (or configured) parameter from the request query string.
     */
    public function extractLinkIdFromRequest(Request $request): ?string
    {
        $linkId = $request->query($this->linkerParam);

        if (! is_string($linkId) || $linkId === '') {
            return null;
        }

        // Validate format — must be a UUID
        if (! Str::isUuid($linkId)) {
            return null;
        }

        return $linkId;
    }

    /**
     * Store a cross-domain link between a source client ID and a target client ID.
     *
     * When a user navigates from domain A (client_id=X) to domain B (client_id=Y),
     * this creates an association so events from both IDs can be merged.
     */
    public function linkClientIds(string $sourceClientId, string $targetClientId): bool
    {
        if ($sourceClientId === $targetClientId) {
            return false;
        }

        $cacheKey = $this->cachePrefix . $sourceClientId;
        $existing = Cache::get($cacheKey);

        // Already linked
        if (is_array($existing) && in_array($targetClientId, $existing, true)) {
            return true;
        }

        $links = is_array($existing) ? $existing : [];
        $links[] = $targetClientId;

        Cache::put($cacheKey, $links, $this->linkTtl);

        // Bidirectional link
        $reverseKey = $this->cachePrefix . $targetClientId;
        $reverseExisting = Cache::get($reverseKey);
        $reverseLinks = is_array($reverseExisting) ? $reverseExisting : [];
        $reverseLinks[] = $sourceClientId;

        Cache::put($reverseKey, $reverseLinks, $this->linkTtl);

        return true;
    }

    /**
     * Get all linked client IDs for a given client ID.
     *
     * @return list<string>
     */
    public function getLinkedClientIds(string $clientId): array
    {
        $cacheKey = $this->cachePrefix . $clientId;
        $links = Cache::get($cacheKey);

        return is_array($links) ? $links : [];
    }

    /**
     * Resolve the "primary" client ID for a cross-domain identity.
     *
     * Returns the original (oldest) client ID in the link chain.
     * Falls back to the provided client ID if no links exist.
     */
    public function resolvePrimaryClientId(string $clientId): string
    {
        $linked = $this->getLinkedClientIds($clientId);

        if ($linked === []) {
            return $clientId;
        }

        // Return the first linked ID (assumed to be the original)
        return $linked[0];
    }

    /**
     * Merge linked client IDs into a unified identity graph.
     *
     * Transitive closure: if A→B and B→C, returns [A, B, C].
     *
     * @return list<string> Unique list of all transitively linked client IDs
     */
    public function resolveIdentityCluster(string $clientId): array
    {
        $visited = [];
        $this->traverseLinks($clientId, $visited);

        return array_values(array_unique($visited));
    }

    /**
     * Check if auto-linker is enabled (URL decoration).
     */
    public function isAutoLinkerEnabled(): bool
    {
        return $this->autoLinkerEnabled;
    }

    /**
     * Generate cross-domain linker configuration for the JS client.
     *
     * Returns the domains, linker param name, and auto-linker flag
     * so the JS client can decorate outbound links.
     *
     * @return array{domains: list<string>, linkerParam: string, autoLinker: bool}
     */
    public function getClientConfig(): array
    {
        return [
            'domains' => $this->domains,
            'linkerParam' => $this->linkerParam,
            'autoLinker' => $this->autoLinkerEnabled,
        ];
    }

    /**
     * Clear a cross-domain link (e.g., on GDPR data erasure).
     */
    public function clearLinks(string $clientId): void
    {
        $linked = $this->getLinkedClientIds($clientId);

        Cache::forget($this->cachePrefix . $clientId);

        // Clear reverse references
        foreach ($linked as $linkedId) {
            $reverseKey = $this->cachePrefix . $linkedId;
            $reverseLinks = Cache::get($reverseKey);

            if (is_array($reverseLinks)) {
                $reverseLinks = array_values(array_filter(
                    $reverseLinks,
                    fn (string $id): bool => $id !== $clientId,
                ));
                Cache::put($reverseKey, $reverseLinks, $this->linkTtl);
            }
        }
    }

    /**
     * Get cross-domain tracking statistics.
     *
     * @return array{enabled: bool, domain_count: int, domains: list<string>, linker_param: string}
     */
    public function getStats(): array
    {
        return [
            'enabled' => $this->enabled,
            'domain_count' => count($this->domains),
            'domains' => $this->domains,
            'linker_param' => $this->linkerParam,
        ];
    }

    /**
     * Traverse the link graph to find all connected client IDs.
     *
     * @param  string  $clientId
     * @param  array<string, bool>  $visited  Mutated in-place for deduplication
     */
    private function traverseLinks(string $clientId, array &$visited): void
    {
        if (isset($visited[$clientId])) {
            return;
        }

        $visited[$clientId] = true;
        $linked = $this->getLinkedClientIds($clientId);

        foreach ($linked as $linkedId) {
            $this->traverseLinks($linkedId, $visited);
        }
    }

    /**
     * Check if a normalized domain matches any configured domain.
     */
    private function domainMatches(string $normalized): bool
    {
        foreach ($this->domains as $configured) {
            $configuredNormalized = strtolower(preg_replace('#^https?://([^/]+).*$#', '$1', $configured) ?? $configured);

            if ($configuredNormalized === $normalized) {
                return true;
            }

            // Support wildcard: .example.com matches subdomain.example.com
            if (str_starts_with($configuredNormalized, '.')) {
                $base = substr($configuredNormalized, 1);
                if (str_ends_with($normalized, $base)) {
                    return true;
                }
            }
        }

        return false;
    }
}
