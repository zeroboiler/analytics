<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Support;

use Illuminate\Contracts\Validation\Rule;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Laravel validation rule for analytics event names.
 *
 * Validates that an event name follows naming conventions and optionally
 * checks against the event catalog and/or strict whitelist.
 *
 * @see \ZeroBoiler\Analytics\Events\EventCatalog
 */
final class AnalyticsEventNameRule implements Rule
{
    private const PATTERN = '/^[a-z][a-z0-9_]{0,99}$/';

    /**
     * @param  bool  $checkCatalog  Whether to verify the event exists in the catalog
     * @param  bool  $strict  Whether to enforce whitelist-only (ignores checkCatalog if true)
     * @param  list<string>  $whitelist  Event name whitelist for strict mode
     */
    public function __construct(
        private readonly bool $checkCatalog = false,
        private readonly bool $strict = false,
        private readonly array $whitelist = [],
    ) {}

    /**
     * Determine if the validation rule passes.
     */
    #[\Override]
    public function passes(mixed $attribute, mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        // Check naming format
        if (preg_match(self::PATTERN, $value) !== 1) {
            return false;
        }

        // Strict whitelist mode
        if ($this->strict && count($this->whitelist) > 0) {
            return in_array($value, $this->whitelist, true);
        }

        // Optional catalog check
        if ($this->checkCatalog && ! EventCatalog::has($value)) {
            return false;
        }

        return true;
    }

    /**
     * Get the validation error message.
     */
    #[\Override]
    public function message(): string
    {
        $base = 'The :attribute must be a valid analytics event name (lowercase, underscores, max 100 chars).';

        if ($this->strict) {
            return $base.' Only whitelisted event names are accepted.';
        }

        if ($this->checkCatalog) {
            return $base.' The event must exist in the analytics catalog.';
        }

        return $base;
    }
}
