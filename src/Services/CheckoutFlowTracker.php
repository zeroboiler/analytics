<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use ZeroBoiler\Analytics\AnalyticsManager;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;
use ZeroBoiler\Analytics\Support\EcommerceFormatConverter;

/**
 * Multi-step checkout funnel tracker.
 *
 * Tracks users through the checkout flow (cart → shipping → payment → confirmation)
 * and computes step-level conversion rates, drop-off analysis, and average
 * time-per-step metrics. Integrates with CartStateManager for cart persistence.
 *
 * Checkout steps:
 *   1. cart_review    — User views their cart
 *   2. shipping_info  — User enters shipping details
 *   3. payment_info  — User enters payment details
 *   4. order_review  — User reviews the final order
 *   5. confirmation  — Order is confirmed/purchased
 *
 * Usage:
 *   $tracker->startCheckout($clientId, $items, $currency);
 *   $tracker->advanceStep($clientId, 'shipping_info');
 *   $tracker->advanceStep($clientId, 'payment_info');
 *   $tracker->completeCheckout($clientId, $transactionId, $value, $currency);
 *
 * @see \ZeroBoiler\Analytics\Services\CartStateManager
 * @see \ZeroBoiler\Analytics\Support\EcommerceFormatConverter
 *
 * @since 6.8.0
 */
final class CheckoutFlowTracker
{
    /** @var list<string> Ordered checkout steps */
    public const STEPS = [
        'cart_review',
        'shipping_info',
        'payment_info',
        'order_review',
        'confirmation',
    ];

    /** @var array<string, int> Step → GA4 checkout_step index mapping */
    private const STEP_INDEX_MAP = [
        'cart_review' => 1,
        'shipping_info' => 2,
        'payment_info' => 3,
        'order_review' => 4,
        'confirmation' => 5,
    ];

    private const CACHE_PREFIX = 'zb_checkout_';

    private const DEFAULT_TTL = 86400; // 24 hours

    private const MAX_STEPS = 10;

    private AnalyticsManager $manager;

    private CacheRepository $cache;

    private bool $enabled;

    private int $cacheTtl;

    private string $defaultCurrency;

    /**
     * @param  AnalyticsManager  $manager
     * @param  CacheRepository  $cache
     * @param  ConfigRepository  $config
     */
    public function __construct(
        AnalyticsManager $manager,
        CacheRepository $cache,
        ConfigRepository $config,
    ){
        $this->manager = $manager;
        $this->cache = $cache;

        $checkoutConfig = $config->get('zeroboiler.analytics.checkout_tracking', []);
        /** @var array{enabled?: bool, cache_ttl?: int, currency?: string} $checkoutConfig */

        $this->enabled = (bool) ($checkoutConfig['enabled'] ?? true);
        $this->cacheTtl = (int) ($checkoutConfig['cache_ttl'] ?? self::DEFAULT_TTL);
        $this->defaultCurrency = (string) ($checkoutConfig['currency'] ?? 'USD');
    }

    /**
     * Check if checkout tracking is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Start a new checkout flow for a client.
     *
     * Initializes checkout tracking state and fires a begin_checkout event
     * with GA4 items format.
     *
     * @param  string  $clientId  Client tracking ID
     * @param  array<int, array{item_id: string, item_name?: string, item_category?: string, price: float, quantity: int}>  $items  Cart items
     * @param  string|null  $currency  Currency code (defaults to config)
     * @param  string|null  $coupon  Coupon code if applicable
     * @return array{checkout_id: string, step: string, step_index: int, value: float, item_count: int}
     */
    public function startCheckout(
        string $clientId,
        array $items,
        ?string $currency = null,
        ?string $coupon = null,
    ): array {
        if (! $this->enabled) {
            return $this->emptyState();
        }

        $checkoutCurrency = $currency ?? $this->defaultCurrency;
        $checkoutId = $this->generateCheckoutId();
        $firstStep = self::STEPS[0];

        $cartValue = EcommerceFormatConverter::buildGa4AddToCart(
            items: $items,
            currency: $checkoutCurrency,
        );

        $totalValue = (float) ($cartValue['value'] ?? 0);
        $itemCount = count($items);

        $state = [
            'checkout_id' => $checkoutId,
            'step' => $firstStep,
            'step_index' => 1,
            'started_at' => time(),
            'steps' => [
                [
                    'step' => $firstStep,
                    'timestamp' => time(),
                ],
            ],
            'value' => $totalValue,
            'item_count' => $itemCount,
            'currency' => $checkoutCurrency,
            'items' => $items,
            'coupon' => $coupon,
            'completed' => false,
        ];

        $this->cache->put(
            self::CACHE_PREFIX . $clientId,
            $state,
            $this->cacheTtl,
        );

        // Fire begin_checkout event with GA4 params
        $params = [
            'currency' => $checkoutCurrency,
            'value' => $totalValue,
            'items' => $items,
            'checkout_step' => 1,
            'checkout_option' => $firstStep,
        ];

        if ($coupon !== null && $coupon !== '') {
            $params['coupon'] = $coupon;
        }

        $this->manager->trackEvent(new AnalyticsEvent(
            name: 'begin_checkout',
            params: $params,
            clientId: $clientId,
        ));

        return [
            'checkout_id' => $checkoutId,
            'step' => $firstStep,
            'step_index' => 1,
            'value' => $totalValue,
            'item_count' => $itemCount,
        ];
    }

    /**
     * Advance the checkout to a specific step.
     *
     * Validates step ordering and fires checkout_step event for funnel tracking.
     * Records timing between steps for drop-off analysis.
     *
     * @param  string  $clientId  Client tracking ID
     * @param  string  $step  Target step name (must be a valid step after current)
     * @param  array<string, mixed>  $options  Additional step options (e.g. shipping_method, payment_type)
     * @return array{checkout_id: string, step: string, step_index: int, previous_step: string|null, time_on_previous: float|int}|null  Null if no active checkout
     */
    public function advanceStep(
        string $clientId,
        string $step,
        array $options = [],
    ): ?array {
        if (! $this->enabled) {
            return null;
        }

        $state = $this->getState($clientId);

        if ($state === null || ($state['completed'] ?? false)) {
            return null;
        }

        // Validate step is in the sequence and comes after current
        $currentStep = $state['step'];
        $currentIdx = array_search($currentStep, self::STEPS, true);
        $targetIdx = array_search($step, self::STEPS, true);

        if ($targetIdx === false || $targetIdx <= $currentIdx) {
            return null;
        }

        $previousStep = $currentStep;
        $timeOnPrevious = time() - ($state['steps'][array_key_last($state['steps'])]['timestamp'] ?? $state['started_at']);

        // Update state
        $state['step'] = $step;
        $state['step_index'] = $targetIdx + 1;
        $state['steps'][] = [
            'step' => $step,
            'timestamp' => time(),
        ];

        $this->cache->put(
            self::CACHE_PREFIX . $clientId,
            $state,
            $this->cacheTtl,
        );

        // Fire checkout_step event
        $stepIndex = self::STEP_INDEX_MAP[$step] ?? ($targetIdx + 1);
        $params = [
            'checkout_step' => $stepIndex,
            'checkout_option' => $step,
            'items' => $state['items'] ?? [],
            'currency' => $state['currency'] ?? $this->defaultCurrency,
            'value' => $state['value'] ?? 0,
        ];

        // Merge additional options (shipping_method, payment_type, etc.)
        foreach ($options as $key => $value) {
            $params[$key] = $value;
        }

        $this->manager->trackEvent(new AnalyticsEvent(
            name: 'checkout_step',
            params: $params,
            clientId: $clientId,
        ));

        return [
            'checkout_id' => $state['checkout_id'],
            'step' => $step,
            'step_index' => $targetIdx + 1,
            'previous_step' => $previousStep,
            'time_on_previous' => $timeOnPrevious,
        ];
    }

    /**
     * Complete the checkout flow.
     *
     * Fires a purchase event with full checkout context and clears the
     * checkout state. Also fires add_payment_info if not already sent.
     *
     * @param  string  $clientId  Client tracking ID
     * @param  string  $transactionId  Order/transaction ID
     * @param  float  $value  Total revenue
     * @param  string|null  $currency  Currency code
     * @param  array{tax?: float, shipping?: float, coupon?: string, shipping_tier?: string, payment_type?: string}  $options  Purchase options
     * @return array{checkout_id: string, transaction_id: string, value: float, total_steps: int, total_time: int, completed: bool}|null
     */
    public function completeCheckout(
        string $clientId,
        string $transactionId,
        float $value,
        ?string $currency = null,
        array $options = [],
    ): ?array {
        if (! $this->enabled) {
            return null;
        }

        $state = $this->getState($clientId);

        if ($state === null || ($state['completed'] ?? false)) {
            return null;
        }

        $purchaseCurrency = $currency ?? $state['currency'] ?? $this->defaultCurrency;
        $items = $state['items'] ?? [];
        $totalSteps = count($state['steps'] ?? []);
        $totalTime = time() - ($state['started_at'] ?? time());

        // Mark as completed
        $state['completed'] = true;
        $state['completed_at'] = time();
        $state['transaction_id'] = $transactionId;
        $state['final_value'] = $value;

        $this->cache->put(
            self::CACHE_PREFIX . $clientId,
            $state,
            $this->cacheTtl,
        );

        // Fire purchase event with GA4 format
        $purchaseParams = EcommerceFormatConverter::buildGa4Purchase(
            transactionId: $transactionId,
            value: $value,
            currency: $purchaseCurrency,
            items: $items,
            options: $options,
        );

        $purchaseParams['checkout_steps_completed'] = $totalSteps;
        $purchaseParams['checkout_duration_seconds'] = $totalTime;

        $this->manager->trackEvent(new AnalyticsEvent(
            name: 'purchase',
            params: $purchaseParams,
            clientId: $clientId,
        ));

        return [
            'checkout_id' => $state['checkout_id'],
            'transaction_id' => $transactionId,
            'value' => $value,
            'total_steps' => $totalSteps,
            'total_time' => $totalTime,
            'completed' => true,
        ];
    }

    /**
     * Abort the checkout flow.
     *
     * Records the abandonment point for funnel analysis and fires
     * a checkout_abandon event.
     *
     * @param  string  $clientId  Client tracking ID
     * @param  string|null  $reason  Optional abandonment reason
     * @return array{checkout_id: string, abandoned_at_step: string, abandoned_at_index: int, value: float, total_time: int}|null
     */
    public function abandonCheckout(string $clientId, ?string $reason = null): ?array
    {
        if (! $this->enabled) {
            return null;
        }

        $state = $this->getState($clientId);

        if ($state === null || ($state['completed'] ?? false)) {
            return null;
        }

        $totalTime = time() - ($state['started_at'] ?? time());

        // Clear checkout state
        $this->cache->forget(self::CACHE_PREFIX . $clientId);

        // Fire checkout abandon event
        $params = [
            'checkout_step' => $state['step_index'] ?? 0,
            'checkout_option' => $state['step'] ?? 'unknown',
            'currency' => $state['currency'] ?? $this->defaultCurrency,
            'value' => $state['value'] ?? 0,
            'items' => $state['items'] ?? [],
            'checkout_duration_seconds' => $totalTime,
        ];

        if ($reason !== null && $reason !== '') {
            $params['abandonment_reason'] = $reason;
        }

        $this->manager->trackEvent(new AnalyticsEvent(
            name: 'checkout_abandon',
            params: $params,
            clientId: $clientId,
        ));

        return [
            'checkout_id' => $state['checkout_id'],
            'abandoned_at_step' => $state['step'] ?? 'unknown',
            'abandoned_at_index' => $state['step_index'] ?? 0,
            'value' => $state['value'] ?? 0,
            'total_time' => $totalTime,
        ];
    }

    /**
     * Get the current checkout state for a client.
     *
     * @param  string  $clientId
     * @return array{checkout_id: string, step: string, step_index: int, started_at: int, steps: array<int, array{step: string, timestamp: int}>, value: float, item_count: int, currency: string, items: array<int, array<string, mixed>>, coupon: string|null, completed: bool, completed_at?: int, transaction_id?: string, final_value?: float}|null
     */
    public function getCheckoutState(string $clientId): ?array
    {
        return $this->getState($clientId);
    }

    /**
     * Get the current step for a client.
     *
     * @return string|null Current step name or null if no active checkout
     */
    public function getCurrentStep(string $clientId): ?string
    {
        $state = $this->getState($clientId);

        if ($state === null || ($state['completed'] ?? false)) {
            return null;
        }

        return $state['step'] ?? null;
    }

    /**
     * Check if a client has an active (in-progress) checkout.
     */
    public function hasActiveCheckout(string $clientId): bool
    {
        $state = $this->getState($clientId);

        return $state !== null && ! ($state['completed'] ?? false);
    }

    /**
     * Compute step-level timing from a checkout state.
     *
     * Returns the time spent on each step in the checkout flow.
     *
     * @param  string  $clientId
     * @return list<array{step: string, duration_seconds: int, duration_formatted: string}>
     */
    public function getStepTiming(string $clientId): array
    {
        $state = $this->getState($clientId);

        if ($state === null) {
            return [];
        }

        $steps = $state['steps'] ?? [];
        $timings = [];

        for ($i = 0; $i < count($steps); $i++) {
            $startTime = $steps[$i]['timestamp'] ?? 0;
            $endTime = ($steps[$i + 1]['timestamp'] ?? $state['completed_at'] ?? time());

            // If this is the last step and checkout is not completed, use current time
            if ($i === count($steps) - 1 && ! ($state['completed'] ?? false)) {
                $endTime = time();
            }

            $duration = max(0, $endTime - $startTime);
            $timings[] = [
                'step' => $steps[$i]['step'] ?? 'unknown',
                'duration_seconds' => $duration,
                'duration_formatted' => $this->formatDuration($duration),
            ];
        }

        return $timings;
    }

    /**
     * Get checkout funnel summary for analytics.
     *
     * Returns conversion data for each step in the checkout flow.
     *
     * @return array{steps: list<array{name: string, label: string, index: int}>}
     */
    public function funnelSteps(): array
    {
        $steps = [];

        foreach (self::STEPS as $index => $step) {
            $steps[] = [
                'name' => $step,
                'label' => $this->stepLabel($step),
                'index' => $index + 1,
            ];
        }

        return ['steps' => $steps];
    }

    /**
     * Get all valid checkout step names.
     *
     * @return list<string>
     */
    public static function validSteps(): array
    {
        return self::STEPS;
    }

    /**
     * Check if a step name is valid.
     */
    public static function isValidStep(string $step): bool
    {
        return in_array($step, self::STEPS, true);
    }

    /**
     * Get step index (1-based) for a step name.
     *
     * @return int 0 if invalid
     */
    public static function stepIndex(string $step): int
    {
        return self::STEP_INDEX_MAP[$step] ?? 0;
    }

    // ── Private Helpers ───────────────────────────────────────────

    /**
     * Get checkout state from cache.
     */
    private function getState(string $clientId): ?array
    {
        if (! $this->enabled) {
            return null;
        }

        $data = $this->cache->get(self::CACHE_PREFIX . $clientId);

        return is_array($data) ? $data : null;
    }

    /**
     * Generate a unique checkout ID.
     */
    private function generateCheckoutId(): string
    {
        return 'cko_' . bin2hex(random_bytes(8));
    }

    /**
     * Get human-readable label for a checkout step.
     */
    private function stepLabel(string $step): string
    {
        return match ($step) {
            'cart_review' => 'Cart Review',
            'shipping_info' => 'Shipping Information',
            'payment_info' => 'Payment Information',
            'order_review' => 'Order Review',
            'confirmation' => 'Order Confirmation',
            default => ucwords(str_replace('_', ' ', $step)),
        };
    }

    /**
     * Format seconds into human-readable duration.
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }

        $minutes = intdiv($seconds, 60);
        $remaining = $seconds % 60;

        if ($minutes < 60) {
            return $remaining > 0
                ? $minutes . 'm ' . $remaining . 's'
                : $minutes . 'm';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return $hours . 'h ' . $remainingMinutes . 'm';
    }

    /**
     * Return an empty/default state.
     *
     * @return array{checkout_id: string, step: string, step_index: int, value: float, item_count: int}
     */
    private function emptyState(): array
    {
        return [
            'checkout_id' => '',
            'step' => '',
            'step_index' => 0,
            'value' => 0.0,
            'item_count' => 0,
        ];
    }
}
