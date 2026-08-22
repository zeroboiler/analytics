<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Pipeline;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\DTO\ConsentState;
use ZeroBoiler\Analytics\Services\ConsentLogService;

/**
 * Granular consent-aware pipeline filter.
 *
 * Unlike the basic ConsentFilter which checks global consent state,
 * this filter evaluates consent at the purpose level. Events are mapped
 * to consent purposes via configurable rules, and only dispatched if
 * all required purposes for that event's category are granted.
 *
 * Purpose mapping (configurable):
 * - page_view, scroll_depth, click, timing → 'analytics'
 * - search, share, form_start, form_submit → 'analytics'
 * - view_item, add_to_cart, purchase, refund → 'analytics' + 'functional'
 * - sign_up, login, trial_start, subscription → 'analytics' + 'functional'
 * - error events → 'necessary' (always allowed)
 *
 * @see \ZeroBoiler\Analytics\DTO\ConsentState
 *
 * @since 1.0.0
 */
final class ConsentAwareFilter
{
    private const CACHE_PREFIX = 'zb_consent_filter_';

    /** @var array<string, list<string>> Default event category → required purposes */
    private const DEFAULT_PURPOSE_MAP = [
        // Engagement events require analytics
        'page_view' => ['analytics'],
        'scroll_depth' => ['analytics'],
        'click' => ['analytics'],
        'error' => ['necessary'],
        'form_start' => ['analytics'],
        'form_submit' => ['analytics'],
        'search' => ['analytics'],
        'share' => ['analytics'],
        'session_start' => ['analytics'],
        'session_end' => ['analytics'],
        'timing' => ['analytics'],
        'screen_view' => ['analytics'],
        'web_vitals' => ['analytics'],
        'notification' => ['functional'],
        'ab_test_exposure' => ['analytics'],
        'file_download' => ['analytics'],
        'video_play' => ['analytics'],
        'js_error' => ['analytics'],
        'outbound_click' => ['analytics'],
        'time_on_page' => ['analytics'],
        'campaign_attribution' => ['analytics'],
        // E-commerce events require analytics + functional
        'view_item' => ['analytics', 'functional'],
        'add_to_cart' => ['analytics', 'functional'],
        'remove_from_cart' => ['analytics', 'functional'],
        'view_cart' => ['analytics', 'functional'],
        'begin_checkout' => ['analytics', 'functional'],
        'add_payment_info' => ['analytics', 'functional'],
        'purchase' => ['analytics', 'functional'],
        'refund' => ['analytics', 'functional'],
        'select_item' => ['analytics', 'functional'],
        'select_promotion' => ['marketing', 'analytics'],
        'view_promotion' => ['marketing', 'analytics'],
        'add_to_wishlist' => ['analytics', 'functional'],
        // SaaS events require analytics + functional
        'sign_up' => ['analytics', 'functional'],
        'login' => ['analytics', 'functional'],
        'logout' => ['analytics', 'functional'],
        'trial_start' => ['analytics', 'functional'],
        'trial_end' => ['analytics', 'functional'],
        'subscription' => ['analytics', 'functional'],
        'subscription_renewal' => ['analytics', 'functional'],
        'plan_upgrade' => ['analytics', 'functional'],
        'plan_downgrade' => ['analytics', 'functional'],
        'cancellation' => ['analytics', 'functional'],
        'identify' => ['necessary'],
        'set_user_properties' => ['functional'],
        'alias' => ['necessary'],
        // Other events default to analytics
        '_default' => ['analytics'],
    ];

    /** @var array<string, list<string>> */
    private array $purposeMap;

    private bool $enabled;

    private ?ConsentLogService $consentLogService;

    /**
     * @param  array<string, list<string>>|null  $customPurposeMap  Override default purpose mapping
     * @param  ConsentLogService|null  $consentLogService  For per-user consent lookup
     */
    public function __construct(
        bool $enabled = true,
        ?array $customPurposeMap = null,
        ?ConsentLogService $consentLogService = null,
    ){
        $this->enabled = $enabled;
        $this->consentLogService = $consentLogService;
        $this->purposeMap = $customPurposeMap ?? self::DEFAULT_PURPOSE_MAP;
    }

    /**
     * Process an event through the consent-aware filter.
     *
     * Returns the event unchanged if all required consent purposes are granted.
     * Returns null if consent is denied for any required purpose, effectively
     * dropping the event from the pipeline.
     *
     * @param  AnalyticsEvent  $event  Event to filter
     * @param  ConsentState|null  $consentState  Current global consent state (fallback)
     * @param  string|null  $identifier  Client/user ID for per-user consent lookup
     * @return AnalyticsEvent|null Event if consent granted, null if denied
     */
    public function process(AnalyticsEvent $event, ?ConsentState $consentState = null, ?string $identifier = null): ?AnalyticsEvent
    {
        if (! $this->enabled) {
            return $event;
        }

        $requiredPurposes = $this->getRequiredPurposes($event->name);

        // 'necessary' purpose is always granted — no filtering needed
        if (count($requiredPurposes) === 1 && $requiredPurposes[0] === 'necessary') {
            return $event;
        }

        // Check per-user granular consent first (if available)
        if ($identifier !== null && $this->consentLogService !== null) {
            foreach ($requiredPurposes as $purpose) {
                if ($purpose === 'necessary') {
                    continue;
                }
                if (! $this->consentLogService->isPurposeGranted($identifier, $purpose)) {
                    return null;
                }
            }

            return $event;
        }

        // Fall back to global consent state
        if ($consentState !== null) {
            return $this->checkGlobalConsent($event, $consentState, $requiredPurposes);
        }

        // No consent info available — allow event through (fail-open)
        return $event;
    }

    /**
     * Check consent against global ConsentState.
     *
     * Maps consent purposes to Consent Mode v2 signals:
     * - analytics → analytics_storage
     * - functional → functionality_storage
     * - marketing → ad_storage
     * - necessary → always allowed
     */
    private function checkGlobalConsent(AnalyticsEvent $event, ConsentState $consentState, array $requiredPurposes): ?AnalyticsEvent
    {
        $signalMap = [
            'analytics' => 'analytics_storage',
            'functional' => 'functionality_storage',
            'marketing' => 'ad_storage',
        ];

        foreach ($requiredPurposes as $purpose) {
            if ($purpose === 'necessary') {
                continue;
            }

            $signal = $signalMap[$purpose] ?? 'analytics_storage';

            if ($consentState->isDenied($signal)) {
                return null;
            }
        }

        return $event;
    }

    /**
     * Get the required consent purposes for an event name.
     *
     * @param  string  $eventName
     * @return list<string>
     */
    public function getRequiredPurposes(string $eventName): array
    {
        return $this->purposeMap[$eventName] ?? $this->purposeMap['_default'] ?? ['analytics'];
    }

    /**
     * Check if an event would be permitted by current consent.
     *
     * Useful for client-side consent-gating (e.g. hiding UI elements
     * when the user hasn't consented to the required purpose).
     */
    public function isPermitted(string $eventName, ?ConsentState $consentState = null, ?string $identifier = null): bool
    {
        $event = new AnalyticsEvent(name: $eventName, params: []);

        return $this->process($event, $consentState, $identifier) !== null;
    }

    /**
     * Get the full purpose mapping table.
     *
     * @return array<string, list<string>>
     */
    public function getPurposeMap(): array
    {
        $map = $this->purposeMap;
        unset($map['_default']);

        return $map;
    }

    /**
     * Add or override a purpose mapping.
     *
     * @param  string  $eventName  Event name or pattern
     * @param  list<string>  $purposes  Required consent purposes
     */
    public function setPurposeMapping(string $eventName, array $purposes): void
    {
        $this->purposeMap[$eventName] = $purposes;
    }

    /**
     * Check if the filter is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the purpose → Consent Mode signal mapping.
     *
     * @return array<string, string>
     */
    public static function purposeToSignalMap(): array
    {
        return [
            'analytics' => 'analytics_storage',
            'functional' => 'functionality_storage',
            'marketing' => 'ad_storage',
            'necessary' => 'security_storage',
        ];
    }
}
