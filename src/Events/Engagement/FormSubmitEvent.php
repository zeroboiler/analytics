<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks a form submission.
 *
 * GA4: generate_lead (standard) or custom form_submit
 * Meta: Lead (standard)
 *
 * @since 1.0.0
 */
final readonly class FormSubmitEvent extends AnalyticsEvent
{
    /**
     * @param  string  $formId  Form element ID
     * @param  string  $formName  Descriptive form name
     * @param  string|null  $formDestination  Where the form submits to
     * @param  float|null  $value  Value associated with the form submission (e.g. lead value)
     * @param  string|null  $currency  Currency code for the value
     */
    public function __construct(
        string $formName = '',
        string $formId = '',
        ?string $formDestination = null,
        ?float $value = null,
        ?string $currency = null,
    ): void {
        parent::__construct('form_submit', array_filter([
            'form_id' => $formId,
            'form_name' => $formName,
            'form_destination' => $formDestination,
            'value' => $value,
            'currency' => $currency,
        ]));
    }
}
