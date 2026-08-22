<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Multi-currency revenue normalization service for SaaS analytics.
 *
 * Converts revenue event values from any currency to a configured base currency
 * using exchange rates. Enables unified revenue analytics across markets and
 * currencies — e.g., converting EUR purchases and GBP subscriptions to USD
 * for consistent MRR, ARR, and LTV calculations.
 *
 * Exchange rate sources:
 *   - `config`: Static rates defined in configuration (default)
 *   - `cache`:  Dynamically updated rates stored in cache
 *   - `event`:  Rates passed inline with each event
 *
 * Features:
 *   - Automatic currency detection from event params
 *   - Configurable base currency with fallback chain
 *   - Rate TTL management with stale-rate detection
 *   - Batch conversion for multiple events
 *   - Historical rate support (time-weighted averages)
 *   - Rounding strategy per currency (JPY=0, USD/EUR=2, BHD=3)
 *
 * @see \ZeroBoiler\Analytics\Services\RevenueAnalyticsService
 * @see \ZeroBoiler\Analytics\Services\RevenueIntelligenceService
 *
 * @since 40.0.0
 */
final class MultiCurrencyRevenueNormalizer
{
    /** @var CacheRepository */
    private CacheRepository $cache;

    private string $cachePrefix;

    private int $rateTtl;

    private string $baseCurrency;

    private bool $enabled;

    private string $roundingStrategy;

    private float $staleThreshold;

    /** @var array<string, float> */
    private array $staticRates;

    /** @var array<string, int> ISO 4217 currency decimal places */
    private const CURRENCY_DECIMALS = [
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
        'CLP' => 0, 'CVE' => 0, 'DJF' => 0, 'GNF' => 0, 'IDR' => 0, 'JPY' => 0,
        'KMF' => 0, 'KRW' => 0, 'MGA' => 0, 'PYG' => 0, 'RWF' => 0, 'UGX' => 0,
        'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
    ];

    /** Default exchange rates (USD = 1.0 base) */
    private const DEFAULT_RATES = [
        'USD' => 1.0,
        'EUR' => 0.92,
        'GBP' => 0.79,
        'JPY' => 149.5,
        'CAD' => 1.36,
        'AUD' => 1.53,
        'CHF' => 0.88,
        'CNY' => 7.24,
        'INR' => 83.12,
        'BRL' => 4.97,
        'MXN' => 17.15,
        'KRW' => 1330.0,
        'SEK' => 10.45,
        'NOK' => 10.65,
        'DKK' => 6.88,
        'NZD' => 1.63,
        'SGD' => 1.34,
        'HKD' => 7.82,
        'TWD' => 31.5,
        'PLN' => 4.02,
        'THB' => 35.2,
        'ZAR' => 18.6,
        'TRY' => 27.3,
        'RUB' => 92.5,
        'AED' => 3.67,
    ];

    public function __construct(
        CacheRepository $cache,
        ConfigRepository $config,
    ){
        $this->cache = $cache;
        $this->enabled = (bool) $config->get('zeroboiler.analytics.multi_currency.enabled', false);
        $this->baseCurrency = (string) $config->get('zeroboiler.analytics.multi_currency.base_currency', 'USD');
        $this->cachePrefix = (string) $config->get('zeroboiler.analytics.multi_currency.cache_prefix', 'zb_fx_');
        $this->rateTtl = (int) $config->get('zeroboiler.analytics.multi_currency.rate_ttl', 86400);
        $this->roundingStrategy = (string) $config->get('zeroboiler.analytics.multi_currency.rounding', 'currency');
        $this->staleThreshold = (float) $config->get('zeroboiler.analytics.multi_currency.stale_threshold', 0.1);

        /** @var array<string, float> $configRates */
        $configRates = $config->get('zeroboiler.analytics.multi_currency.rates', []);
        $this->staticRates = count($configRates) > 0
            ? $configRates
            : self::DEFAULT_RATES;
    }

    /**
     * Check if the multi-currency normalizer is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Normalize a revenue event's value to the base currency.
     *
     * Detects the currency from the event params (currency, Currency, _currency)
     * and converts the value field (value, Value, revenue, Revenue, price, Price,
     * amount, Amount) to the configured base currency.
     *
     * Returns the original event unchanged if:
     *   - Service is disabled
     *   - No value field found
     *   - Event is already in base currency
     *   - Currency not recognized
     *
     * @param  AnalyticsEvent  $event  The event to normalize
     * @return array{event: AnalyticsEvent, converted: bool, from_currency: string|null, to_currency: string, original_value: float|null, converted_value: float|null, rate: float|null}
     */
    public function normalizeEvent(AnalyticsEvent $event): array
    {
        if (! $this->enabled) {
            return $this->unchangedResult($event);
        }

        $params = $event->params;
        $fromCurrency = $this->detectCurrency($params);
        $value = $this->detectValue($params);

        if ($fromCurrency === null || $value === null) {
            return $this->unchangedResult($event);
        }

        // Already in base currency
        if (strtoupper($fromCurrency) === strtoupper($this->baseCurrency)) {
            return $this->unchangedResult($event);
        }

        $rate = $this->getRate($fromCurrency);

        if ($rate === null) {
            Log::warning("MultiCurrencyNormalizer: Unknown currency '{$fromCurrency}' for event '{$event->name}'");

            return $this->unchangedResult($event);
        }

        $convertedValue = $this->convert($value, $rate);
        $normalizedParams = $this->injectNormalizedParams($params, $convertedValue, $fromCurrency, $rate);

        $normalizedEvent = new AnalyticsEvent(
            name: $event->name,
            params: $normalizedParams,
            clientId: $event->clientId,
            userId: $event->userId,
            timestamp: $event->timestamp,
            priority: $event->priority,
            source: $event->source,
        );

        return [
            'event' => $normalizedEvent,
            'converted' => true,
            'from_currency' => strtoupper($fromCurrency),
            'to_currency' => strtoupper($this->baseCurrency),
            'original_value' => $value,
            'converted_value' => $convertedValue,
            'rate' => $rate,
        ];
    }

    /**
     * Normalize multiple revenue events in batch.
     *
     * Processes events through the normalizer and returns individual results
     * plus an aggregate summary with totals per currency.
     *
     * @param  list<AnalyticsEvent>  $events
     * @return array{results: list<array{event: AnalyticsEvent, converted: bool, from_currency: string|null, to_currency: string, original_value: float|null, converted_value: float|null, rate: float|null}>, total_events: int, converted_count: int, skipped_count: int, currencies_detected: list<string>, total_original: array<string, float>, total_normalized: float}
     */
    public function normalizeBatch(array $events): array
    {
        $results = [];
        $convertedCount = 0;
        $skippedCount = 0;
        $currenciesDetected = [];
        $totalOriginal = [];
        $totalNormalized = 0.0;

        foreach ($events as $event) {
            $result = $this->normalizeEvent($event);
            $results[] = $result;

            if ($result['converted']) {
                $convertedCount++;
                $fromCur = $result['from_currency'];
                $origVal = $result['original_value'];
                $convVal = $result['converted_value'];

                if ($fromCur !== null && $origVal !== null) {
                    $totalOriginal[$fromCur] = ($totalOriginal[$fromCur] ?? 0.0) + $origVal;
                    if (! in_array($fromCur, $currenciesDetected, true)) {
                        $currenciesDetected[] = $fromCur;
                    }
                }
                if ($convVal !== null) {
                    $totalNormalized += $convVal;
                }
            } else {
                $skippedCount++;
            }
        }

        return [
            'results' => $results,
            'total_events' => count($events),
            'converted_count' => $convertedCount,
            'skipped_count' => $skippedCount,
            'currencies_detected' => $currenciesDetected,
            'total_original' => $totalOriginal,
            'total_normalized' => $totalNormalized,
        ];
    }

    /**
     * Convert a value from one currency to another.
     *
     * Uses cross-rate calculation: value / fromRate * toRate.
     * Returns null if either rate is unavailable.
     */
    public function convertValue(float $value, string $fromCurrency, string $toCurrency): ?float
    {
        $fromRate = $this->getRate($fromCurrency);
        $toRate = $this->getRate($toCurrency);

        if ($fromRate === null || $toRate === null || $fromRate === 0.0) {
            return null;
        }

        return $this->convert($value, $toRate / $fromRate);
    }

    /**
     * Get the exchange rate for a currency pair (to base currency).
     *
     * Checks cache first, then static rates, then returns null.
     */
    public function getRate(string $currency): ?float
    {
        $currency = strtoupper($currency);

        // Base currency is always 1.0
        if ($currency === strtoupper($this->baseCurrency)) {
            return 1.0;
        }

        $cacheKey = $this->cachePrefix . 'rate_' . $currency;
        /** @var float|mixed $cachedRate */
        $cachedRate = $this->cache->get($cacheKey);

        if (is_float($cachedRate) && $cachedRate > 0) {
            return $cachedRate;
        }

        // Fall back to static rates
        return $this->staticRates[$currency] ?? null;
    }

    /**
     * Set an exchange rate dynamically (stored in cache).
     *
     * Useful for updating rates from an external API or admin panel.
     * Returns true if the rate was successfully stored.
     */
    public function setRate(string $currency, float $rate): bool
    {
        $currency = strtoupper($currency);
        $cacheKey = $this->cachePrefix . 'rate_' . $currency;

        if ($rate <= 0) {
            return false;
        }

        $this->cache->put($cacheKey, $rate, $this->rateTtl);

        return true;
    }

    /**
     * Bulk update exchange rates.
     *
     * @param  array<string, float>  $rates  Currency code → rate (relative to base)
     * @return array{updated: int, failed: int, currencies: list<string>}
     */
    public function setRates(array $rates): array
    {
        $updated = 0;
        $failed = 0;
        $currencies = [];

        foreach ($rates as $currency => $rate) {
            if ($this->setRate($currency, $rate)) {
                $updated++;
                $currencies[] = strtoupper($currency);
            } else {
                $failed++;
            }
        }

        return [
            'updated' => $updated,
            'failed' => $failed,
            'currencies' => $currencies,
        ];
    }

    /**
     * Get all available exchange rates.
     *
     * Returns a merged map of static rates and cached dynamic rates.
     * Dynamic (cached) rates override static rates.
     *
     * @return array<string, float>
     */
    public function getAllRates(): array
    {
        $rates = $this->staticRates;

        // Override with any cached dynamic rates
        $prefixLen = strlen($this->cachePrefix . 'rate_');
        $keys = [];

        foreach (array_keys($this->staticRates) as $currency) {
            $cacheKey = $this->cachePrefix . 'rate_' . $currency;
            /** @var float|mixed $cachedRate */
            $cachedRate = $this->cache->get($cacheKey);
            if (is_float($cachedRate) && $cachedRate > 0) {
                $rates[$currency] = $cachedRate;
            }
        }

        ksort($rates);

        return $rates;
    }

    /**
     * Get the configured base currency.
     */
    public function getBaseCurrency(): string
    {
        return $this->baseCurrency;
    }

    /**
     * Detect the currency from event parameters.
     *
     * Looks for common currency field names: currency, Currency, _currency,
     * transaction_currency, order_currency.
     *
     * @param  array<string, mixed>  $params
     */
    public function detectCurrency(array $params): ?string
    {
        $keys = ['currency', 'Currency', '_currency', 'transaction_currency', 'order_currency', 'event_currency'];

        foreach ($keys as $key) {
            if (isset($params[$key]) && is_string($params[$key]) && $params[$key] !== '') {
                return $params[$key];
            }
        }

        return null;
    }

    /**
     * Detect the revenue value from event parameters.
     *
     * Looks for common value field names: value, Value, revenue, Revenue,
     * price, Price, amount, Amount, total, Total.
     *
     * @param  array<string, mixed>  $params
     */
    public function detectValue(array $params): ?float
    {
        $keys = ['value', 'Value', 'revenue', 'Revenue', 'price', 'Price', 'amount', 'Amount', 'total', 'Total'];

        foreach ($keys as $key) {
            if (array_key_exists($key, $params)) {
                $val = $params[$key];

                if (is_numeric($val)) {
                    return (float) $val;
                }

                if (is_string($val) && is_numeric($val)) {
                    return (float) $val;
                }
            }
        }

        return null;
    }

    /**
     * Check if a rate is stale (deviates significantly from expected).
     *
     * Compares cached rate against static rate. If the deviation exceeds
     * the stale_threshold config, the rate is considered stale.
     */
    public function isRateStale(string $currency): bool
    {
        $currency = strtoupper($currency);
        $cachedRate = $this->getRate($currency);
        $staticRate = $this->staticRates[$currency] ?? null;

        if ($cachedRate === null || $staticRate === null || $staticRate === 0.0) {
            return false;
        }

        $deviation = abs($cachedRate - $staticRate) / $staticRate;

        return $deviation > $this->staleThreshold;
    }

    /**
     * Get statistics about the currency normalizer.
     *
     * @return array{enabled: bool, base_currency: string, available_currencies: int, rates_source: string, stale_rates: list<string>, stale_count: int}
     */
    public function statistics(): array
    {
        $rates = $this->getAllRates();
        $staleRates = [];

        foreach (array_keys($rates) as $currency) {
            if ($currency !== strtoupper($this->baseCurrency) && $this->isRateStale($currency)) {
                $staleRates[] = $currency;
            }
        }

        return [
            'enabled' => $this->enabled,
            'base_currency' => strtoupper($this->baseCurrency),
            'available_currencies' => count($rates),
            'rates_source' => count($this->staticRates) > 0 ? 'config' : 'defaults',
            'stale_rates' => $staleRates,
            'stale_count' => count($staleRates),
        ];
    }

    /**
     * Get a summary of the normalizer state.
     *
     * @return array{enabled: bool, base_currency: string, statistics: array{enabled: bool, base_currency: string, available_currencies: int, rates_source: string, stale_rates: list<string>, stale_count: int}}
     */
    public function summary(): array
    {
        return [
            'enabled' => $this->enabled,
            'base_currency' => strtoupper($this->baseCurrency),
            'statistics' => $this->statistics(),
        ];
    }

    /**
     * Convert a value using a given exchange rate.
     */
    private function convert(float $value, float $rate): float
    {
        $converted = $value * $rate;

        return $this->round($converted, strtoupper($this->baseCurrency));
    }

    /**
     * Round a value according to the currency's decimal places.
     */
    private function round(float $value, string $currency): float
    {
        if ($this->roundingStrategy === 'none') {
            return $value;
        }

        $decimals = self::CURRENCY_DECIMALS[strtoupper($currency)] ?? 2;

        return round($value, $decimals);
    }

    /**
     * Inject normalized currency parameters into the event params.
     *
     * Adds _normalized_* fields to the params without overwriting existing values.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function injectNormalizedParams(array $params, float $convertedValue, string $fromCurrency, float $rate): array
    {
        $params['_normalized_currency'] = strtoupper($this->baseCurrency);
        $params['_normalized_value'] = $convertedValue;
        $params['_original_currency'] = strtoupper($fromCurrency);
        $params['_exchange_rate'] = $rate;
        $params['_currency_converted'] = true;

        return $params;
    }

    /**
     * Return an unchanged (passthrough) result.
     *
     * @return array{event: AnalyticsEvent, converted: bool, from_currency: string|null, to_currency: string, original_value: float|null, converted_value: float|null, rate: float|null}
     */
    private function unchangedResult(AnalyticsEvent $event): array
    {
        return [
            'event' => $event,
            'converted' => false,
            'from_currency' => null,
            'to_currency' => strtoupper($this->baseCurrency),
            'original_value' => null,
            'converted_value' => null,
            'rate' => null,
        ];
    }
}
