<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Events\SaaS\AccountActivatedEvent;
use ZeroBoiler\Analytics\Events\SaaS\AccountDeactivatedEvent;
use ZeroBoiler\Analytics\Events\SaaS\CancellationEvent;
use ZeroBoiler\Analytics\Events\SaaS\CreditAppliedEvent;
use ZeroBoiler\Analytics\Events\SaaS\EmailVerifiedEvent;
use ZeroBoiler\Analytics\Events\SaaS\FeatureLimitReachedEvent;
use ZeroBoiler\Analytics\Events\SaaS\FeatureUsedEvent;
use ZeroBoiler\Analytics\Events\SaaS\IntegrationConnectedEvent;
use ZeroBoiler\Analytics\Events\SaaS\IntegrationFailedEvent;
use ZeroBoiler\Analytics\Events\SaaS\InviteSentEvent;
use ZeroBoiler\Analytics\Events\SaaS\InvoiceGeneratedEvent;
use ZeroBoiler\Analytics\Events\SaaS\LoginEvent;
use ZeroBoiler\Analytics\Events\SaaS\LogoutEvent;
use ZeroBoiler\Analytics\Events\SaaS\PasswordChangedEvent;
use ZeroBoiler\Analytics\Events\SaaS\PasswordResetEvent;
use ZeroBoiler\Analytics\Events\SaaS\PaymentFailedEvent;
use ZeroBoiler\Analytics\Events\SaaS\PaymentMethodAddedEvent;
use ZeroBoiler\Analytics\Events\SaaS\PaymentSucceededEvent;
use ZeroBoiler\Analytics\Events\SaaS\PlanDowngradeEvent;
use ZeroBoiler\Analytics\Events\SaaS\PlanUpgradeEvent;
use ZeroBoiler\Analytics\Events\SaaS\ProfileUpdatedEvent;
use ZeroBoiler\Analytics\Events\SaaS\RoleChangedEvent;
use ZeroBoiler\Analytics\Events\SaaS\SignUpEvent;
use ZeroBoiler\Analytics\Events\SaaS\SubscriptionEvent;
use ZeroBoiler\Analytics\Events\SaaS\SubscriptionRenewalEvent;
use ZeroBoiler\Analytics\Events\SaaS\TeamCreatedEvent;
use ZeroBoiler\Analytics\Events\SaaS\TeamMemberJoinedEvent;
use ZeroBoiler\Analytics\Events\SaaS\TeamMemberRemovedEvent;
use ZeroBoiler\Analytics\Events\SaaS\TrialEndEvent;
use ZeroBoiler\Analytics\Events\SaaS\TrialStartEvent;
use ZeroBoiler\Analytics\Events\Engagement\ConsentGrantedEvent;
use ZeroBoiler\Analytics\Events\Engagement\ConsentWithdrawnEvent;
use ZeroBoiler\Analytics\Events\Engagement\ErrorEvent;
use ZeroBoiler\Analytics\Events\Engagement\FormSubmitEvent;
use ZeroBoiler\Analytics\Events\Engagement\SearchEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\PurchaseEvent;
use ZeroBoiler\Analytics\Events\Ecommerce\RefundEvent;
use ZeroBoiler\Analytics\Events\SaaS\DataErasureCompletedEvent;
use ZeroBoiler\Analytics\Events\SaaS\DataSubjectAccessRequestEvent;
use ZeroBoiler\Analytics\Events\SaaS\PlanChangedEvent;
use ZeroBoiler\Analytics\Events\SaaS\PaymentMethodUpdatedEvent;
use ZeroBoiler\Analytics\Events\SaaS\SubscriptionCancelledEvent;
use ZeroBoiler\Analytics\Events\SaaS\SubscriptionCreatedEvent;
use ZeroBoiler\Analytics\Events\SaaS\SubscriptionResumedEvent;
use ZeroBoiler\Analytics\Events\SaaS\TrialExpiredEvent;

/**
 * Config-driven lifecycle event mapping service.
 *
 * Provides a declarative way to map application events (Laravel events,
 * model events, custom dispatched events) to ZeroBoiler analytics events.
 * Supports parameter extraction, conditional mapping, and priority ordering.
 *
 * Configuration is read from `zeroboiler.analytics.lifecycle`.
 *
 * @see \ZeroBoiler\Analytics\Tracking\ServerSideTracker
 *
 * @since 1.0.0
 */
final class LifecycleEventMapper
{
    /**
     * Built-in lifecycle mapping templates for common SaaS patterns.
     *
     * Covers authentication, subscription, trial, feature usage, e-commerce,
     * engagement, account lifecycle, B2B/team, billing, and operational events.
     *
     * @var array<string, array{source: string, target: class-string<AnalyticsEvent>, params_extractor?: string, condition?: string, priority?: int}>
     */
    private const DEFAULT_MAPPINGS = [
        // ── Authentication Lifecycle ───────────────────────────────
        'auth.login' => [
            'source' => 'Illuminate\Auth\Events\Login',
            'target' => LoginEvent::class,
            'params_extractor' => 'extractAuthParams',
            'priority' => 100,
        ],
        'auth.register' => [
            'source' => 'Illuminate\Auth\Events\Registered',
            'target' => SignUpEvent::class,
            'params_extractor' => 'extractRegisterParams',
            'priority' => 100,
        ],
        'auth.logout' => [
            'source' => 'Illuminate\Auth\Events\Logout',
            'target' => LogoutEvent::class,
            'params_extractor' => 'extractLogoutParams',
            'priority' => 50,
        ],

        // ── Subscription Lifecycle ──────────────────────────────────
        'subscription.created' => [
            'source' => 'subscription.created',
            'target' => SubscriptionEvent::class,
            'params_extractor' => 'extractSubscriptionParams',
            'priority' => 90,
        ],
        'subscription.upgraded' => [
            'source' => 'subscription.upgraded',
            'target' => PlanUpgradeEvent::class,
            'params_extractor' => 'extractPlanChangeParams',
            'priority' => 90,
        ],
        'subscription.downgraded' => [
            'source' => 'subscription.downgraded',
            'target' => PlanDowngradeEvent::class,
            'params_extractor' => 'extractPlanChangeParams',
            'priority' => 90,
        ],
        'subscription.cancelled' => [
            'source' => 'subscription.cancelled',
            'target' => CancellationEvent::class,
            'params_extractor' => 'extractCancellationParams',
            'priority' => 90,
        ],
        'subscription.renewal' => [
            'source' => 'subscription.renewal',
            'target' => SubscriptionRenewalEvent::class,
            'params_extractor' => 'extractSubscriptionParams',
            'priority' => 85,
        ],

        // ── Trial Lifecycle ─────────────────────────────────────────
        'trial.started' => [
            'source' => 'trial.started',
            'target' => TrialStartEvent::class,
            'params_extractor' => 'extractTrialParams',
            'priority' => 85,
        ],
        'trial.ended' => [
            'source' => 'trial.ended',
            'target' => TrialEndEvent::class,
            'params_extractor' => 'extractTrialParams',
            'priority' => 85,
        ],

        // ── Feature Usage ──────────────────────────────────────────
        'feature.used' => [
            'source' => 'feature.used',
            'target' => FeatureUsedEvent::class,
            'params_extractor' => 'extractFeatureParams',
            'priority' => 80,
        ],
        'feature.limit_reached' => [
            'source' => 'feature.limit_reached',
            'target' => FeatureLimitReachedEvent::class,
            'params_extractor' => 'extractSimpleUserIdParams',
            'priority' => 80,
        ],

        // ── E-commerce Lifecycle ──────────────────────────────────
        'order.completed' => [
            'source' => 'order.completed',
            'target' => PurchaseEvent::class,
            'params_extractor' => 'extractPurchaseParams',
            'priority' => 95,
        ],
        'order.refunded' => [
            'source' => 'order.refunded',
            'target' => RefundEvent::class,
            'params_extractor' => 'extractRefundParams',
            'priority' => 95,
        ],

        // ── Engagement Lifecycle ───────────────────────────────────
        'form.submitted' => [
            'source' => 'form.submitted',
            'target' => FormSubmitEvent::class,
            'params_extractor' => 'extractFormParams',
            'priority' => 70,
        ],
        'search.performed' => [
            'source' => 'search.performed',
            'target' => SearchEvent::class,
            'params_extractor' => 'extractSearchParams',
            'priority' => 70,
        ],
        'error.occurred' => [
            'source' => 'error.occurred',
            'target' => ErrorEvent::class,
            'params_extractor' => 'extractErrorParams',
            'priority' => 60,
        ],

        // ── Account Lifecycle ──────────────────────────────────────
        'account.activated' => [
            'source' => 'account.activated',
            'target' => AccountActivatedEvent::class,
            'params_extractor' => 'extractSimpleUserIdParams',
            'priority' => 80,
        ],
        'account.deactivated' => [
            'source' => 'account.deactivated',
            'target' => AccountDeactivatedEvent::class,
            'params_extractor' => 'extractSimpleUserIdParams',
            'priority' => 80,
        ],
        'account.email_verified' => [
            'source' => 'account.email_verified',
            'target' => EmailVerifiedEvent::class,
            'params_extractor' => 'extractSimpleUserIdParams',
            'priority' => 75,
        ],
        'account.password_changed' => [
            'source' => 'account.password_changed',
            'target' => PasswordChangedEvent::class,
            'params_extractor' => 'extractSimpleUserIdParams',
            'priority' => 70,
        ],
        'account.password_reset' => [
            'source' => 'account.password_reset',
            'target' => PasswordResetEvent::class,
            'params_extractor' => 'extractSimpleUserIdParams',
            'priority' => 70,
        ],
        'account.profile_updated' => [
            'source' => 'account.profile_updated',
            'target' => ProfileUpdatedEvent::class,
            'params_extractor' => 'extractSimpleUserIdParams',
            'priority' => 60,
        ],

        // ── B2B / Team Lifecycle ───────────────────────────────────
        'team.created' => [
            'source' => 'team.created',
            'target' => TeamCreatedEvent::class,
            'params_extractor' => 'extractTeamParams',
            'priority' => 85,
        ],
        'team.member_joined' => [
            'source' => 'team.member_joined',
            'target' => TeamMemberJoinedEvent::class,
            'params_extractor' => 'extractTeamParams',
            'priority' => 80,
        ],
        'team.member_removed' => [
            'source' => 'team.member_removed',
            'target' => TeamMemberRemovedEvent::class,
            'params_extractor' => 'extractTeamParams',
            'priority' => 80,
        ],
        'team.role_changed' => [
            'source' => 'team.role_changed',
            'target' => RoleChangedEvent::class,
            'params_extractor' => 'extractRoleChangeParams',
            'priority' => 75,
        ],
        'team.invite_sent' => [
            'source' => 'team.invite_sent',
            'target' => InviteSentEvent::class,
            'params_extractor' => 'extractInviteParams',
            'priority' => 70,
        ],

        // ── Billing Lifecycle ──────────────────────────────────────
        'billing.payment_succeeded' => [
            'source' => 'billing.payment_succeeded',
            'target' => PaymentSucceededEvent::class,
            'params_extractor' => 'extractPaymentParams',
            'priority' => 90,
        ],
        'billing.payment_failed' => [
            'source' => 'billing.payment_failed',
            'target' => PaymentFailedEvent::class,
            'params_extractor' => 'extractPaymentParams',
            'priority' => 90,
        ],
        'billing.payment_method_added' => [
            'source' => 'billing.payment_method_added',
            'target' => PaymentMethodAddedEvent::class,
            'params_extractor' => 'extractSimpleUserIdParams',
            'priority' => 70,
        ],
        'billing.invoice_generated' => [
            'source' => 'billing.invoice_generated',
            'target' => InvoiceGeneratedEvent::class,
            'params_extractor' => 'extractPaymentParams',
            'priority' => 70,
        ],
        'billing.credit_applied' => [
            'source' => 'billing.credit_applied',
            'target' => CreditAppliedEvent::class,
            'params_extractor' => 'extractPaymentParams',
            'priority' => 70,
        ],

        // ── Integration Lifecycle ──────────────────────────────────
        'integration.connected' => [
            'source' => 'integration.connected',
            'target' => IntegrationConnectedEvent::class,
            'params_extractor' => 'extractIntegrationParams',
            'priority' => 75,
        ],
        'integration.failed' => [
            'source' => 'integration.failed',
            'target' => IntegrationFailedEvent::class,
            'params_extractor' => 'extractIntegrationParams',
            'priority' => 60,
        ],

        // ── Conversion & Expansion Lifecycle (v2.76) ────────────────
        'trial.converted' => [
            'source' => 'trial.converted',
            'target' => \ZeroBoiler\Analytics\Events\SaaS\TrialConvertedEvent::class,
            'params_extractor' => 'extractTrialParams',
            'priority' => 95,
        ],
        'subscription.value_changed' => [
            'source' => 'subscription.value_changed',
            'target' => \ZeroBoiler\Analytics\Events\SaaS\SubscriptionValueChangedEvent::class,
            'params_extractor' => 'extractSubscriptionParams',
            'priority' => 85,
        ],
        'usage.quota_reached' => [
            'source' => 'usage.quota_reached',
            'target' => \ZeroBoiler\Analytics\Events\SaaS\UsageQuotaReachedEvent::class,
            'params_extractor' => 'extractSimpleUserIdParams',
            'priority' => 80,
        ],
        'billing.retry' => [
            'source' => 'billing.retry',
            'target' => \ZeroBoiler\Analytics\Events\SaaS\BillingRetryEvent::class,
            'params_extractor' => 'extractPaymentParams',
            'priority' => 90,
        ],
        'subscription.paused' => [
            'source' => 'subscription.paused',
            'target' => \ZeroBoiler\Analytics\Events\SaaS\SubscriptionPausedEvent::class,
            'params_extractor' => 'extractSubscriptionParams',
            'priority' => 85,
        ],
        'workspace.created' => [
            'source' => 'workspace.created',
            'target' => \ZeroBoiler\Analytics\Events\SaaS\WorkspaceCreatedEvent::class,
            'params_extractor' => 'extractTeamParams',
            'priority' => 80,
        ],
        'milestone.reached' => [
            'source' => 'milestone.reached',
            'target' => \ZeroBoiler\Analytics\Events\SaaS\MilestoneReachedEvent::class,
            'params_extractor' => 'extractSimpleUserIdParams',
            'priority' => 70,
        ],

        // ── Expansion & Growth Lifecycle (v2.77) ─────────────────────
        'team.invite_accepted' => [
            'source' => 'team.invite_accepted',
            'target' => \ZeroBoiler\Analytics\Events\SaaS\TeamMemberJoinedEvent::class,
            'params_extractor' => 'extractTeamParams',
            'priority' => 75,
        ],
        'subscription.trial_end_reminder' => [
            'source' => 'subscription.trial_end_reminder',
            'target' => \ZeroBoiler\Analytics\Events\SaaS\TrialEndEvent::class,
            'params_extractor' => 'extractTrialParams',
            'priority' => 80,
        ],

        // ── GDPR & Account Lifecycle (v2.90) ──────────────────────
        'account.deleted' => [
            'source' => 'account.deleted',
            'target' => \ZeroBoiler\Analytics\Events\SaaS\AccountDeletedEvent::class,
            'params_extractor' => 'extractSimpleUserIdParams',
            'priority' => 95,
        ],

        // ── GDPR Consent Lifecycle (v2.93) ───────────────────────
        'consent.granted' => [
            'source' => 'consent.granted',
            'target' => \ZeroBoiler\Analytics\Events\Engagement\ConsentGrantedEvent::class,
            'params_extractor' => 'extractConsentParams',
            'priority' => 90,
        ],
        'consent.withdrawn' => [
            'source' => 'consent.withdrawn',
            'target' => \ZeroBoiler\Analytics\Events\Engagement\ConsentWithdrawnEvent::class,
            'params_extractor' => 'extractConsentParams',
            'priority' => 90,
        ],
        'gdpr.data_subject_access_request' => [
            'source' => 'gdpr.data_subject_access_request',
            'target' => \ZeroBoiler\Analytics\Events\SaaS\DataSubjectAccessRequestEvent::class,
            'params_extractor' => 'extractGdprParams',
            'priority' => 95,
        ],
        'gdpr.data_erasure_completed' => [
            'source' => 'gdpr.data_erasure_completed',
            'target' => \ZeroBoiler\Analytics\Events\SaaS\DataErasureCompletedEvent::class,
            'params_extractor' => 'extractGdprParams',
            'priority' => 95,
        ],

        // ── Plan Management (v2.93) ────────────────────────────────
        'plan.changed' => [
            'source' => 'plan.changed',
            'target' => \ZeroBoiler\Analytics\Events\SaaS\PlanChangedEvent::class,
            'params_extractor' => 'extractPlanChangeParams',
            'priority' => 90,
        ],
        'billing.payment_method_updated' => [
            'source' => 'billing.payment_method_updated',
            'target' => \ZeroBoiler\Analytics\Events\SaaS\PaymentMethodUpdatedEvent::class,
            'params_extractor' => 'extractSimpleUserIdParams',
            'priority' => 70,
        ],

        // ── Subscription Lifecycle Expansion (v2.93) ───────────────
        'subscription.created_new' => [
            'source' => 'subscription.created_new',
            'target' => \ZeroBoiler\Analytics\Events\SaaS\SubscriptionCreatedEvent::class,
            'params_extractor' => 'extractSubscriptionParams',
            'priority' => 90,
        ],
        'subscription.cancelled_new' => [
            'source' => 'subscription.cancelled_new',
            'target' => \ZeroBoiler\Analytics\Events\SaaS\SubscriptionCancelledEvent::class,
            'params_extractor' => 'extractCancellationParams',
            'priority' => 90,
        ],
        'subscription.resumed' => [
            'source' => 'subscription.resumed',
            'target' => \ZeroBoiler\Analytics\Events\SaaS\SubscriptionResumedEvent::class,
            'params_extractor' => 'extractSubscriptionParams',
            'priority' => 85,
        ],
        'trial.expired' => [
            'source' => 'trial.expired',
            'target' => \ZeroBoiler\Analytics\Events\SaaS\TrialExpiredEvent::class,
            'params_extractor' => 'extractTrialParams',
            'priority' => 85,
        ],

        // ── SLA & Compliance Lifecycle (v10.8.0) ───────────────────
        'sla.breach' => [
            'source' => 'sla.breach',
            'target' => \ZeroBoiler\Analytics\Events\SaaS\SlaBreachEvent::class,
            'params_extractor' => 'extractSimpleUserIdParams',
            'priority' => 95,
        ],

        // ── Feature Adoption Lifecycle (v10.8.0) ────────────────────
        'feature.adopted' => [
            'source' => 'feature.adopted',
            'target' => \ZeroBoiler\Analytics\Events\SaaS\FeatureAdoptedEvent::class,
            'params_extractor' => 'extractFeatureParams',
            'priority' => 75,
        ],

        // ── Revenue Expansion Lifecycle (v10.8.0) ──────────────────
        'revenue.expansion' => [
            'source' => 'revenue.expansion',
            'target' => \ZeroBoiler\Analytics\Events\SaaS\ExpansionRevenueEvent::class,
            'params_extractor' => 'extractSubscriptionParams',
            'priority' => 90,
        ],
    ];

    /** @var array<string, array{source: string, target: string, params_extractor?: string, condition?: string, priority?: int}> */
    private array $activeMappings = [];

    /** @var array<string, bool> */
    private array $enabledToggles = [];

    private bool $enabled;

    private AnalyticsManager $manager;

    /**
     * @param  AnalyticsManager  $manager
     * @param  ConfigRepository  $config
     */
    public function __construct(AnalyticsManager $manager, ConfigRepository $config): void
    {
        $this->manager = $manager;

        $lifecycleConfig = $config->get('zeroboiler.analytics.lifecycle', []);
        /** @var array{enabled?: bool, events?: array<string, bool>, custom_mappings?: array<string, array{source: string, target: string, params_extractor?: string, condition?: string, priority?: int}>, override_defaults?: bool} $lifecycleConfig */

        $this->enabled = (bool) ($lifecycleConfig['enabled'] ?? true);
        $this->enabledToggles = $lifecycleConfig['events'] ?? [];

        // Build active mappings: defaults + custom
        $overrideDefaults = (bool) ($lifecycleConfig['override_defaults'] ?? false);

        if ($overrideDefaults) {
            $this->activeMappings = $lifecycleConfig['custom_mappings'] ?? [];
        } else {
            $this->activeMappings = self::DEFAULT_MAPPINGS;

            // Merge custom mappings (can override defaults by key)
            $customMappings = $lifecycleConfig['custom_mappings'] ?? [];
            foreach ($customMappings as $key => $mapping) {
                $this->activeMappings[$key] = $mapping;
            }
        }

        // Sort by priority (highest first)
        uasort($this->activeMappings, function (array $a, array $b): int {
            return ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0);
        });
    }

    /**
     * Register all lifecycle event listeners on the dispatcher.
     *
     * @param  EventDispatcher  $dispatcher
     */
    public function register(EventDispatcher $dispatcher): void
    {
        if (! $this->enabled) {
            return;
        }

        foreach ($this->activeMappings as $eventKey => $mapping) {
            if (! $this->isMappingEnabled($eventKey)) {
                continue;
            }

            $this->registerMapping($dispatcher, $eventKey, $mapping);
        }
    }

    /**
     * Register a single event mapping.
     *
     * @param  EventDispatcher  $dispatcher
     * @param  string  $eventKey
     * @param  array{source: string, target: string, params_extractor?: string, condition?: string, priority?: int}  $mapping
     */
    public function registerMapping(EventDispatcher $dispatcher, string $eventKey, array $mapping): void
    {
        $source = $mapping['source'];
        $target = $mapping['target'];
        $extractor = $mapping['params_extractor'] ?? null;
        $condition = $mapping['condition'] ?? null;

        $manager = $this->manager;

        $dispatcher->listen($source, function (mixed $payload) use ($manager, $target, $extractor, $condition, $eventKey): void {
            // Check conditional filter
            if ($condition !== null && is_string($condition) && method_exists($this, $condition)) {
                if (! $this->{$condition}($payload)) {
                    return;
                }
            }

            try {
                $event = $this->buildEvent($target, $payload, $extractor);
                $manager->trackEvent($event);
            } catch (\Throwable $e) {
                try {
                    Log::warning('LifecycleEventMapper: failed to map event', [
                        'key' => $eventKey,
                        'target' => $target,
                        'error' => $e->getMessage(),
                    ]);
                } catch (\Throwable) {
                    // Log facade unavailable
                }
            }
        });
    }

    /**
     * Build an analytics event from a mapping target and payload.
     *
     * @param  string  $targetClass
     * @param  mixed  $payload
     * @param  string|null  $extractor
     */
    private function buildEvent(string $targetClass, mixed $payload, ?string $extractor): AnalyticsEvent
    {
        // Use named extractor method if available
        if ($extractor !== null && method_exists($this, $extractor)) {
            return $this->{$extractor}($targetClass, $payload);
        }

        // If payload is already an AnalyticsEvent, return it
        if ($payload instanceof AnalyticsEvent) {
            return $payload;
        }

        // Try to construct with payload as named params
        if (is_array($payload)) {
            return $this->constructWithParams($targetClass, $payload);
        }

        // Try reflection-based construction
        return $this->constructWithReflection($targetClass, $payload);
    }

    /**
     * Construct an event class with an associative array of params.
     *
     * @param  string  $class
     * @param  array<string, mixed>  $params
     */
    private function constructWithParams(string $class, array $params): AnalyticsEvent
    {
        if (! class_exists($class)) {
            return new AnalyticsEvent(name: 'unknown_lifecycle', params: $params);
        }

        // Convert snake_case keys to camelCase for constructor matching
        $camelParams = [];
        foreach ($params as $key => $value) {
            $camelParams[str_replace('_', '', ucwords($key, '_'))] = $value;
        }

        try {
            return new $class(...$camelParams);
        } catch (\Throwable) {
            // Fallback: generic AnalyticsEvent
            $eventName = $this->extractEventName($class);

            return new AnalyticsEvent(name: $eventName, params: $params);
        }
    }

    /**
     * Construct an event using reflection to match constructor params.
     *
     * @param  string  $class
     * @param  mixed  $payload
     */
    private function constructWithReflection(string $class, mixed $payload): AnalyticsEvent
    {
        if (! class_exists($class)) {
            return new AnalyticsEvent(name: 'unknown_lifecycle', params: []);
        }

        try {
            $reflection = new \ReflectionClass($class);
            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                return new $class;
            }

            $args = [];
            foreach ($constructor->getParameters() as $param) {
                $name = $param->getName();

                if (is_object($payload) && property_exists($payload, $name)) {
                    $args[] = $payload->{$name};
                } elseif (is_array($payload) && isset($payload[$name])) {
                    $args[] = $payload[$name];
                } elseif (is_object($payload) && method_exists($payload, $this->getterName($name))) {
                    $args[] = $payload->{$this->getterName($name)}();
                } else {
                    $args[] = $param->getDefaultValue();
                }
            }

            return new $class(...$args);
        } catch (\Throwable) {
            return new AnalyticsEvent(
                name: $this->extractEventName($class),
                params: is_array($payload) ? $payload : ['raw' => true],
            );
        }
    }

    /**
     * Extract the event name from a class by stripping namespace.
     */
    private function extractEventName(string $class): string
    {
        $short = (new \ReflectionClass($class))->getShortName();

        // Convert PascalCase to snake_case
        return strtolower(preg_replace('/(?<!^)([A-Z])/', '_$1', $short) ?? $short);
    }

    /**
     * Convert a property name to a getter method name.
     */
    private function getterName(string $name): string
    {
        return 'get' . ucfirst($name);
    }

    /**
     * Check if a mapping is enabled via config toggles.
     */
    private function isMappingEnabled(string $eventKey): bool
    {
        if (empty($this->enabledToggles)) {
            return true;
        }

        return (bool) ($this->enabledToggles[$eventKey] ?? true);
    }

    // ── Param Extractors ───────────────────────────────────────────

    /**
     * Extract params from Illuminate Auth Login event.
     */
    private function extractAuthParams(string $class, mixed $payload): AnalyticsEvent
    {
        $method = '';

        if (is_object($payload)) {
            $method = property_exists($payload, 'guard')
                ? (string) $payload->guard
                : '';
        }

        return new LoginEvent(method: $method);
    }

    /**
     * Extract params from Illuminate Auth Registered event.
     */
    private function extractRegisterParams(string $class, mixed $payload): AnalyticsEvent
    {
        return new SignUpEvent(method: 'default');
    }

    /**
     * Extract params from Illuminate Auth Logout event.
     */
    private function extractLogoutParams(string $class, mixed $payload): AnalyticsEvent
    {
        return new LogoutEvent;
    }

    /**
     * Extract params from subscription events.
     *
     * @param  string  $class
     * @param  array<string, mixed>|object  $payload
     */
    private function extractSubscriptionParams(string $class, mixed $payload): AnalyticsEvent
    {
        $params = $this->payloadToArray($payload);

        return new SubscriptionEvent(
            planName: (string) ($params['plan_name'] ?? $params['planName'] ?? ''),
            price: (float) ($params['price'] ?? 0.0),
            currency: (string) ($params['currency'] ?? 'USD'),
            userId: (string) ($params['user_id'] ?? $params['userId'] ?? ''),
        );
    }

    /**
     * Extract params from plan upgrade/downgrade events.
     *
     * @param  string  $class
     * @param  array<string, mixed>|object  $payload
     */
    private function extractPlanChangeParams(string $class, mixed $payload): AnalyticsEvent
    {
        $params = $this->payloadToArray($payload);

        return match ($class) {
            PlanUpgradeEvent::class => new PlanUpgradeEvent(
                fromPlan: (string) ($params['from_plan'] ?? $params['fromPlan'] ?? ''),
                toPlan: (string) ($params['to_plan'] ?? $params['toPlan'] ?? ''),
                userId: (string) ($params['user_id'] ?? $params['userId'] ?? ''),
            ),
            PlanDowngradeEvent::class => new PlanDowngradeEvent(
                fromPlan: (string) ($params['from_plan'] ?? $params['fromPlan'] ?? ''),
                toPlan: (string) ($params['to_plan'] ?? $params['toPlan'] ?? ''),
                userId: (string) ($params['user_id'] ?? $params['userId'] ?? ''),
            ),
            default => new AnalyticsEvent(name: 'plan_change', params: $params),
        };
    }

    /**
     * Extract params from cancellation events.
     *
     * @param  string  $class
     * @param  array<string, mixed>|object  $payload
     */
    private function extractCancellationParams(string $class, mixed $payload): AnalyticsEvent
    {
        $params = $this->payloadToArray($payload);

        return new CancellationEvent(
            reason: (string) ($params['reason'] ?? ''),
            planName: (string) ($params['plan_name'] ?? $params['planName'] ?? ''),
            userId: (string) ($params['user_id'] ?? $params['userId'] ?? ''),
        );
    }

    /**
     * Extract params from trial events.
     *
     * @param  string  $class
     * @param  array<string, mixed>|object  $payload
     */
    private function extractTrialParams(string $class, mixed $payload): AnalyticsEvent
    {
        $params = $this->payloadToArray($payload);

        return match ($class) {
            TrialStartEvent::class => new TrialStartEvent(
                planName: (string) ($params['plan_name'] ?? $params['planName'] ?? ''),
                trialDays: (int) ($params['trial_days'] ?? $params['trialDays'] ?? 14),
                userId: (string) ($params['user_id'] ?? $params['userId'] ?? ''),
            ),
            TrialEndEvent::class => new TrialEndEvent(
                outcome: (string) ($params['outcome'] ?? 'expired'),
                planName: (string) ($params['plan_name'] ?? $params['planName'] ?? ''),
                userId: (string) ($params['user_id'] ?? $params['userId'] ?? ''),
            ),
            default => new AnalyticsEvent(name: 'trial_event', params: $params),
        };
    }

    /**
     * Extract params from feature usage events.
     *
     * @param  string  $class
     * @param  array<string, mixed>|object  $payload
     */
    private function extractFeatureParams(string $class, mixed $payload): AnalyticsEvent
    {
        $params = $this->payloadToArray($payload);

        return new FeatureUsedEvent(
            featureName: (string) ($params['feature_name'] ?? $params['featureName'] ?? ''),
            category: (string) ($params['category'] ?? ''),
            userId: (string) ($params['user_id'] ?? $params['userId'] ?? ''),
        );
    }

    /**
     * Extract params from purchase events.
     *
     * @param  string  $class
     * @param  array<string, mixed>|object  $payload
     */
    private function extractPurchaseParams(string $class, mixed $payload): AnalyticsEvent
    {
        $params = $this->payloadToArray($payload);

        return new PurchaseEvent(
            transactionId: (string) ($params['transaction_id'] ?? $params['transactionId'] ?? ''),
            value: (float) ($params['value'] ?? $params['revenue'] ?? 0.0),
            currency: (string) ($params['currency'] ?? 'USD'),
            items: (array) ($params['items'] ?? []),
        );
    }

    /**
     * Extract params from refund events.
     *
     * @param  string  $class
     * @param  array<string, mixed>|object  $payload
     */
    private function extractRefundParams(string $class, mixed $payload): AnalyticsEvent
    {
        $params = $this->payloadToArray($payload);

        return new RefundEvent(
            transactionId: (string) ($params['transaction_id'] ?? $params['transactionId'] ?? ''),
            value: (float) ($params['value'] ?? 0.0),
            currency: (string) ($params['currency'] ?? 'USD'),
            reason: (string) ($params['reason'] ?? ''),
        );
    }

    /**
     * Extract params from form submit events.
     *
     * @param  string  $class
     * @param  array<string, mixed>|object  $payload
     */
    private function extractFormParams(string $class, mixed $payload): AnalyticsEvent
    {
        $params = $this->payloadToArray($payload);

        return new FormSubmitEvent(
            formId: (string) ($params['form_id'] ?? $params['formId'] ?? ''),
            formName: (string) ($params['form_name'] ?? $params['formName'] ?? ''),
        );
    }

    /**
     * Extract params from search events.
     *
     * @param  string  $class
     * @param  array<string, mixed>|object  $payload
     */
    private function extractSearchParams(string $class, mixed $payload): AnalyticsEvent
    {
        $params = $this->payloadToArray($payload);

        return new SearchEvent(
            query: (string) ($params['query'] ?? $params['search_term'] ?? ''),
            resultsCount: (int) ($params['results_count'] ?? $params['resultsCount'] ?? 0),
            category: (string) ($params['category'] ?? ''),
        );
    }

    /**
     * Extract params from error events.
     *
     * @param  string  $class
     * @param  array<string, mixed>|object  $payload
     */
    private function extractErrorParams(string $class, mixed $payload): AnalyticsEvent
    {
        $params = $this->payloadToArray($payload);

        return new ErrorEvent(
            message: (string) ($params['message'] ?? $params['error_message'] ?? ''),
            source: (string) ($params['source'] ?? ''),
            severity: (string) ($params['severity'] ?? 'error'),
        );
    }

    /**
     * Extract simple user_id-based params for account lifecycle events.
     *
     * Uses reflection-based construction because these event constructors
     * vary widely and user_id is typically passed via metadata:
     * - AccountActivatedEvent(method, metadata)
     * - AccountDeactivatedEvent(reason, permanent, metadata)
     * - EmailVerifiedEvent(method, metadata)
     * - PasswordChangedEvent(method, metadata)
     * - PasswordResetEvent(method, success, metadata)
     * - ProfileUpdatedEvent(fields, metadata)
     * - PaymentMethodAddedEvent(paymentMethod, brand, isDefault, metadata)
     * - FeatureLimitReachedEvent(featureName, limitType, currentUsage, maxLimit, metadata)
     *
     * @param  string  $class
     * @param  array<string, mixed>|object  $payload
     */
    private function extractSimpleUserIdParams(string $class, mixed $payload): AnalyticsEvent
    {
        $params = $this->payloadToArray($payload);
        $userId = (string) ($params['user_id'] ?? $params['userId'] ?? '');

        // Use constructWithReflection which gracefully handles mismatched params
        return $this->constructWithReflection($class, array_merge($params, [
            'metadata' => array_filter(array_merge($params['metadata'] ?? [], [
                'user_id' => $userId,
            ])),
        ]));
    }

    /**
     * Extract params from team events (created, member_joined, member_removed).
     *
     * Uses reflection-based construction because team event constructors vary:
     * - TeamCreatedEvent(teamName, memberCount, plan, metadata)
     * - TeamMemberJoinedEvent(role, inviteMethod, metadata)
     * - TeamMemberRemovedEvent(role, reason, metadata)
     *
     * @param  string  $class
     * @param  array<string, mixed>|object  $payload
     */
    private function extractTeamParams(string $class, mixed $payload): AnalyticsEvent
    {
        $params = $this->payloadToArray($payload);

        // Map payload keys to the constructor parameter names for each event
        $mapped = match ($class) {
            TeamCreatedEvent::class => [
                'teamName' => (string) ($params['team_name'] ?? $params['teamName'] ?? ''),
                'memberCount' => isset($params['member_count']) ? (int) $params['member_count'] : null,
                'plan' => (string) ($params['plan'] ?? ''),
            ],
            TeamMemberJoinedEvent::class => [
                'role' => (string) ($params['role'] ?? ''),
                'inviteMethod' => (string) ($params['invite_method'] ?? $params['inviteMethod'] ?? ''),
            ],
            TeamMemberRemovedEvent::class => [
                'role' => (string) ($params['role'] ?? ''),
                'reason' => (string) ($params['reason'] ?? ''),
            ],
            default => $params,
        };

        // Attach user_id as metadata when not a direct constructor param
        if (! empty($params['user_id'] ?? $params['userId'])) {
            $mapped['metadata'] = array_merge($mapped['metadata'] ?? [], [
                'user_id' => (string) ($params['user_id'] ?? $params['userId'] ?? ''),
                'team_id' => (string) ($params['team_id'] ?? $params['teamId'] ?? ''),
            ]);
        }

        return $this->constructWithParams($class, $mapped);
    }

    /**
     * Extract params from role change events.
     *
     * RoleChangedEvent constructor: (fromRole, toRole, changedBy, metadata)
     *
     * @param  string  $class
     * @param  array<string, mixed>|object  $payload
     */
    private function extractRoleChangeParams(string $class, mixed $payload): AnalyticsEvent
    {
        $params = $this->payloadToArray($payload);
        $userId = (string) ($params['user_id'] ?? $params['userId'] ?? '');

        return new RoleChangedEvent(
            fromRole: (string) ($params['previous_role'] ?? $params['from_role'] ?? ''),
            toRole: (string) ($params['new_role'] ?? $params['to_role'] ?? ''),
            changedBy: (string) ($params['changed_by'] ?? $params['changedBy'] ?? ''),
            metadata: array_filter(['user_id' => $userId]),
        );
    }

    /**
     * Extract params from invite events.
     *
     * InviteSentEvent constructor: (inviteType, role, userId, extra)
     *
     * @param  string  $class
     * @param  array<string, mixed>|object  $payload
     */
    private function extractInviteParams(string $class, mixed $payload): AnalyticsEvent
    {
        $params = $this->payloadToArray($payload);

        return new InviteSentEvent(
            inviteType: (string) ($params['invite_type'] ?? $params['inviteType'] ?? 'team_member'),
            role: (string) ($params['role'] ?? null),
            userId: (string) ($params['user_id'] ?? $params['userId'] ?? null),
            extra: array_filter([
                'team_id' => (string) ($params['team_id'] ?? $params['teamId'] ?? ''),
                'invitee_email' => (string) ($params['invitee_email'] ?? $params['inviteeEmail'] ?? ''),
            ]),
        );
    }

    /**
     * Extract params from billing/payment events.
     *
     * Event constructors vary:
     * - PaymentSucceededEvent(amount, currency, paymentMethod, invoiceId, metadata)
     * - PaymentFailedEvent(reason, amount, currency, paymentMethod, metadata)
     * - InvoiceGeneratedEvent(invoiceId, amount, currency, status, metadata)
     * - CreditAppliedEvent(amount, currency, reason, source, metadata)
     * - SubscriptionRenewalEvent(planName, amount, currency, billingCycle, params)
     *
     * @param  string  $class
     * @param  array<string, mixed>|object  $payload
     */
    private function extractPaymentParams(string $class, mixed $payload): AnalyticsEvent
    {
        $params = $this->payloadToArray($payload);

        $mapped = match ($class) {
            PaymentSucceededEvent::class => [
                'amount' => (float) ($params['amount'] ?? 0.0),
                'currency' => (string) ($params['currency'] ?? 'USD'),
                'paymentMethod' => (string) ($params['payment_method'] ?? $params['paymentMethod'] ?? ''),
                'invoiceId' => (string) ($params['invoice_id'] ?? $params['invoiceId'] ?? ''),
                'metadata' => array_filter(['user_id' => (string) ($params['user_id'] ?? $params['userId'] ?? '')]),
            ],
            PaymentFailedEvent::class => [
                'reason' => (string) ($params['reason'] ?? ''),
                'amount' => (float) ($params['amount'] ?? 0.0),
                'currency' => (string) ($params['currency'] ?? 'USD'),
                'paymentMethod' => (string) ($params['payment_method'] ?? $params['paymentMethod'] ?? ''),
                'metadata' => array_filter(['user_id' => (string) ($params['user_id'] ?? $params['userId'] ?? '')]),
            ],
            InvoiceGeneratedEvent::class => [
                'invoiceId' => (string) ($params['invoice_id'] ?? $params['invoiceId'] ?? ''),
                'amount' => (float) ($params['amount'] ?? 0.0),
                'currency' => (string) ($params['currency'] ?? 'USD'),
                'status' => (string) ($params['status'] ?? ''),
                'metadata' => array_filter(['user_id' => (string) ($params['user_id'] ?? $params['userId'] ?? '')]),
            ],
            CreditAppliedEvent::class => [
                'amount' => (float) ($params['amount'] ?? 0.0),
                'currency' => (string) ($params['currency'] ?? 'USD'),
                'reason' => (string) ($params['reason'] ?? ''),
                'source' => (string) ($params['source'] ?? ''),
                'metadata' => array_filter(['user_id' => (string) ($params['user_id'] ?? $params['userId'] ?? '')]),
            ],
            SubscriptionRenewalEvent::class => [
                'planName' => (string) ($params['plan_name'] ?? $params['planName'] ?? ''),
                'amount' => (float) ($params['amount'] ?? 0.0),
                'currency' => (string) ($params['currency'] ?? 'USD'),
                'billingCycle' => (string) ($params['billing_cycle'] ?? $params['billingCycle'] ?? ''),
                'params' => array_filter(['user_id' => (string) ($params['user_id'] ?? $params['userId'] ?? '')]),
            ],
            default => $params,
        };

        return $this->constructWithParams($class, $mapped);
    }

    /**
     * Extract params from integration events.
     *
     * Event constructors vary:
     * - IntegrationConnectedEvent(integrationName, userId, extra)
     * - IntegrationFailedEvent(integrationName, errorType, errorMessage, isRetryable, metadata)
     *
     * @param  string  $class
     * @param  array<string, mixed>|object  $payload
     */
    private function extractIntegrationParams(string $class, mixed $payload): AnalyticsEvent
    {
        $params = $this->payloadToArray($payload);

        $mapped = match ($class) {
            IntegrationConnectedEvent::class => [
                'integrationName' => (string) ($params['integration_name'] ?? $params['integrationName'] ?? $params['provider'] ?? ''),
                'userId' => (string) ($params['user_id'] ?? $params['userId'] ?? ''),
                'extra' => array_filter([
                    'provider' => (string) ($params['provider'] ?? ''),
                ]),
            ],
            IntegrationFailedEvent::class => [
                'integrationName' => (string) ($params['integration_name'] ?? $params['integrationName'] ?? $params['provider'] ?? ''),
                'errorType' => (string) ($params['error_type'] ?? $params['errorType'] ?? 'unknown'),
                'errorMessage' => (string) ($params['error_message'] ?? $params['errorMessage'] ?? ''),
                'isRetryable' => (bool) ($params['is_retryable'] ?? $params['isRetryable'] ?? false),
                'metadata' => array_filter(['user_id' => (string) ($params['user_id'] ?? $params['userId'] ?? '')]),
            ],
            default => $params,
        };

        return $this->constructWithParams($class, $mapped);
    }

    /**
     * Extract params from consent granted/withdrawn events.
     *
     * @param  string  $class
     * @param  array<string, mixed>|object  $payload
     */
    private function extractConsentParams(string $class, mixed $payload): AnalyticsEvent
    {
        $params = $this->payloadToArray($payload);

        $mapped = match ($class) {
            ConsentGrantedEvent::class => [
                'consentCategory' => (string) ($params['consent_category'] ?? $params['category'] ?? 'analytics'),
                'consentStatus' => 'granted',
                'userId' => (string) ($params['user_id'] ?? $params['userId'] ?? ''),
                'extra' => array_filter([
                    'source' => (string) ($params['source'] ?? 'banner'),
                ]),
            ],
            ConsentWithdrawnEvent::class => [
                'consentCategory' => (string) ($params['consent_category'] ?? $params['category'] ?? 'analytics'),
                'consentStatus' => 'withdrawn',
                'userId' => (string) ($params['user_id'] ?? $params['userId'] ?? ''),
                'extra' => array_filter([
                    'source' => (string) ($params['source'] ?? 'settings'),
                ]),
            ],
            default => $params,
        };

        return $this->constructWithParams($class, $mapped);
    }

    /**
     * Extract params from GDPR compliance events.
     *
     * @param  string  $class
     * @param  array<string, mixed>|object  $payload
     */
    private function extractGdprParams(string $class, mixed $payload): AnalyticsEvent
    {
        $params = $this->payloadToArray($payload);

        $mapped = match ($class) {
            DataSubjectAccessRequestEvent::class => [
                'requestType' => 'access',
                'userId' => (string) ($params['user_id'] ?? $params['userId'] ?? ''),
                'extra' => array_filter([
                    'request_id' => (string) ($params['request_id'] ?? $params['requestId'] ?? ''),
                ]),
            ],
            DataErasureCompletedEvent::class => [
                'requestType' => 'erasure',
                'userId' => (string) ($params['user_id'] ?? $params['userId'] ?? ''),
                'extra' => array_filter([
                    'request_id' => (string) ($params['request_id'] ?? $params['requestId'] ?? ''),
                    'completed_at' => (string) ($params['completed_at'] ?? $params['completedAt'] ?? ''),
                ]),
            ],
            default => $params,
        };

        return $this->constructWithParams($class, $mapped);
    }

    /**
     * Convert a payload to an associative array.
     *
     * @return array<string, mixed>
     */
    private function payloadToArray(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (is_object($payload) && method_exists($payload, 'toArray')) {
            return $payload->toArray();
        }

        if (is_object($payload)) {
            return get_object_vars($payload);
        }

        return [];
    }

    // ── Conditional Filters ──────────────────────────────────────

    /**
     * Example condition: only track if user is authenticated.
     */
    private function requireAuth(mixed $payload): bool
    {
        if (is_object($payload) && property_exists($payload, 'user')) {
            return $payload->user !== null;
        }

        if (is_array($payload) && isset($payload['user_id'])) {
            return $payload['user_id'] !== null && $payload['user_id'] !== '';
        }

        return false;
    }

    /**
     * Get all active mappings.
     *
     * @return array<string, array{source: string, target: string, priority: int, enabled: bool}>
     */
    public function getMappings(): array
    {
        $result = [];

        foreach ($this->activeMappings as $key => $mapping) {
            $result[$key] = [
                'source' => $mapping['source'],
                'target' => $mapping['target'],
                'priority' => $mapping['priority'] ?? 0,
                'enabled' => $this->isMappingEnabled($key),
            ];
        }

        return $result;
    }

    /**
     * Get the number of registered mappings.
     */
    public function count(): int
    {
        return count($this->activeMappings);
    }

    /**
     * Check if the lifecycle mapper is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get the event keys that are currently enabled.
     *
     * @return list<string>
     */
    public function enabledEventKeys(): array
    {
        $keys = [];

        foreach (array_keys($this->activeMappings) as $key) {
            if ($this->isMappingEnabled($key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Get a summary of the lifecycle mapper state.
     *
     * @return array{enabled: bool, total_mappings: int, enabled_count: int, categories: array<string, int>, event_keys: list<string>}
     */
    public function summary(): array
    {
        $categories = [];
        $keys = $this->enabledEventKeys();

        foreach ($keys as $key) {
            $parts = explode('.', $key);
            $category = $parts[0] ?? 'unknown';
            $categories[$category] = ($categories[$category] ?? 0) + 1;
        }

        return [
            'enabled' => $this->enabled,
            'total_mappings' => $this->count(),
            'enabled_count' => count($keys),
            'categories' => $categories,
            'event_keys' => $keys,
        ];
    }
}
