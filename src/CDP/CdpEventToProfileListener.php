<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\CDP;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * CDP Event-to-Profile Listener — bridges analytics events to CDP profile updates.
 *
 * Listens for analytics events dispatched through the pipeline and
 * automatically updates CDP profiles based on event data:
 *
 * - **Purchase events** → increment total_revenue, update purchase_count
 * - **Signup/Login events** → set identity traits (email, name)
 * - **Plan upgrade/downgrade** → set plan_name trait
 * - **Feature used** → track feature adoption
 * - **Session start** → increment session count
 *
 * Also extracts identity signals from events:
 * - `user_id` property → triggers profile creation/linking
 * - `email` property → updates email trait
 * - `name`/`first_name`/`last_name` → updates name traits
 *
 * This listener is registered in the AnalyticsServiceProvider and processes
 * events asynchronously when queue is enabled.
 *
 * @see \ZeroBoiler\Analytics\CDP\CdpProfileService
 *
 * @since 196.0.0
 */
final class CdpEventToProfileListener
{
    /** @var array<string, string> Event properties to map to CDP traits */
    private const IDENTITY_PROPERTY_MAP = [
        'email' => 'email',
        'name' => 'name',
        'first_name' => 'first_name',
        'last_name' => 'last_name',
        'company' => 'company',
        'plan' => 'plan',
        'role' => 'role',
        'country' => 'country',
        'city' => 'city',
        'phone' => 'phone',
        'website' => 'website',
        'avatar' => 'avatar',
    ];

    private readonly bool $enabled;

    /** @var array<string, bool> Event types to process (null = all) */
    private readonly array $processEvents;

    /**
     * @param  CdpProfileService  $profileService
     * @param  ConfigRepository  $config
     */
    public function __construct(
        private readonly CdpProfileService $profileService,
        ConfigRepository $config,
    ) {
        $cdpConfig = $config->get('zeroboiler.analytics.cdp', []);
        /** @var array{enabled?: bool, auto_process_events?: bool, process_events?: list<string>} $cdpConfig */

        $this->enabled = (bool) ($cdpConfig['enabled'] ?? true);
        $this->enabled = $this->enabled && (bool) ($cdpConfig['auto_process_events'] ?? true);
        $this->processEvents = (array) ($cdpConfig['process_events'] ?? []);
    }

    /**
     * Handle an analytics event — update CDP profile if applicable.
     *
     * @param  AnalyticsEvent  $event  The analytics event
     * @return array{processed: bool, user_id: string|null, updated_traits: list<string>, segments: list<string>}|null
     */
    public function handle(AnalyticsEvent $event): ?array
    {
        if (! $this->enabled) {
            return null;
        }

        // Extract user ID from event
        $userId = $this->extractUserId($event);

        if ($userId === null || $userId === '') {
            return null;
        }

        // Check if this event type should be processed
        if ($this->processEvents !== [] && ! in_array($event->name, $this->processEvents, true)) {
            return null;
        }

        // Extract identity traits from event properties
        $identityTraits = $this->extractIdentityTraits($event);
        if ($identityTraits !== []) {
            $this->profileService->identify($userId, $identityTraits);
        }

        // Process event for computed traits
        $result = $this->profileService->processEvent($event, $userId);

        return [
            'processed' => true,
            'user_id' => $userId,
            'updated_traits' => $result['updated_traits'],
            'segments' => $result['segments'],
        ];
    }

    /**
     * Extract user ID from event context or properties.
     *
     * @param  AnalyticsEvent  $event
     * @return string|null
     */
    private function extractUserId(AnalyticsEvent $event): ?string
    {
        // Check context first (set by pipeline enrichers)
        $userId = $event->context['user_id'] ?? null;

        if ($userId !== null && $userId !== '') {
            return (string) $userId;
        }

        // Check properties
        $userId = $event->properties['user_id'] ?? null;

        if ($userId !== null && $userId !== '') {
            return (string) $userId;
        }

        return null;
    }

    /**
     * Extract identity-relevant traits from event properties.
     *
     * Maps known property names to CDP trait names.
     *
     * @param  AnalyticsEvent  $event
     * @return array<string, mixed>
     */
    private function extractIdentityTraits(AnalyticsEvent $event): array
    {
        $traits = [];

        foreach (self::IDENTITY_PROPERTY_MAP as $eventProp => $traitName) {
            if (isset($event->properties[$eventProp])) {
                $value = $event->properties[$eventProp];

                if (is_string($value) && $value !== '') {
                    $traits[$traitName] = $value;
                }
            }
        }

        return $traits;
    }
}
