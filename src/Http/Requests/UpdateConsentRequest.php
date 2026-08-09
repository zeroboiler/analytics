<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for updating consent state.
 *
 * Validates the POST /api/analytics/consent endpoint.
 * Accepts consent signals in Google Consent Mode v2 format.
 *
 * @see \ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::updateConsent()
 */
final class UpdateConsentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get validation rules for consent update.
     *
     * Accepts Google Consent Mode v2 signal keys:
     * analytics_storage, ad_storage, ad_user_data, ad_personalization,
     * functionality_storage, personalization_storage, security_storage
     *
     * @return array<string, string|array<string, string>>
     */
    public function rules(): array
    {
        return [
            'signals' => 'required|array|min:1',
            'signals.*' => 'string|in:granted,denied',
            'source' => 'sometimes|string|in:banner,api,preference_center,cookiebot,osano,custom',
        ];
    }

    /**
     * Get custom attribute names for validation errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'signals' => 'consent signals',
            'source' => 'consent source',
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'signals.required' => 'At least one consent signal is required.',
            'signals.array' => 'Consent signals must be an object.',
            'signals.*.in' => 'Each consent signal must be "granted" or "denied".',
            'source.in' => 'Invalid consent source. Must be one of: banner, api, preference_center, cookiebot, osano, custom.',
        ];
    }

    /**
     * Get the consent signals from the request.
     *
     * Returns a map of consent signal keys to their values ('granted' or 'denied').
     * Only includes valid signals.
     *
     * @return array<string, string>
     */
    public function signals(): array
    {
        $signals = $this->input('signals', []);

        if (! is_array($signals)) {
            return [];
        }

        $valid = [];
        foreach ($signals as $key => $value) {
            if (is_string($key) && is_string($value) && in_array($value, ['granted', 'denied'], true)) {
                $valid[$key] = $value;
            }
        }

        return $valid;
    }

    /**
     * Get the consent source (where the consent was set from).
     */
    public function source(): ?string
    {
        $source = $this->input('source');

        return is_string($source) && $source !== '' ? $source : null;
    }
}
