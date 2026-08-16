<?php

/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for opting in to analytics tracking.
 *
 * Validates the POST /api/analytics/opt-in endpoint.
 * Requires authentication — the user must be logged in to opt in.
 *
 * @see \ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::optIn()
 *
 * @since 201.0.0
 */
final class OptInRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Requires authentication — opt-in is a per-user action.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get validation rules for opt-in.
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
