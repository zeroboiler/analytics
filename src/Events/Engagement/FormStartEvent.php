<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Events\Engagement;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Tracks when a user starts interacting with a form.
 *
 * GA4: form_start (custom)
 * Meta: (custom)
 *
 * @since 1.0.0
 */
final readonly class FormStartEvent extends AnalyticsEvent
{
    /**
     * @param  string  $formId  Form element ID
     * @param  string  $formName  Descriptive form name
     * @param  string  $formDestination  Where the form submits to
     */
    public function __construct(
        string $formName = '',
        string $formId = '',
        string $formDestination = '',
    ): void {
        parent::__construct('form_start', array_filter([
            'form_id' => $formId,
            'form_name' => $formName,
            'form_destination' => $formDestination,
        ]));
    }
}
