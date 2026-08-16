<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for opting out of analytics tracking.
 *
 * Validates the POST /api/analytics/opt-out endpoint.
 * Requires authentication — the user must be logged in to opt out.
 *
 * @see \ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::optOut()
 *
 * @since 201.0.0
 */
final class OptOutRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Requires authentication — opt-out is a per-user action.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get validation rules for opt-out.
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Get the authenticated user ID as a string.
     */
    public function userId(): ?string
    {
        $user = $this->user();

        if ($user === null) {
            return null;
        }

        $key = $user->getKey();

        return is_int($key) || is_string($key) ? (string) $key : null;
    }
}
