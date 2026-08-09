<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for user identity linking.
 *
 * Validates the POST /api/analytics/identify endpoint.
 * Links a client ID to an authenticated user and optionally sets user traits/properties.
 *
 * @see \ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::identify()
 */
final class IdentifyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Requires authentication — the route middleware handles this,
     * but we double-check for safety.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get validation rules for identity linking.
     *
     * @return array<string, string|array<string, string>>
     */
    public function rules(): array
    {
        return [
            'client_id' => 'required|string|max:64',
            'traits' => 'array',
            'traits.*' => 'mixed',
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
            'client_id' => 'client ID',
            'traits' => 'user traits',
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
            'client_id.required' => 'The client ID is required for identification.',
            'client_id.max' => 'The client ID must not exceed 64 characters.',
            'traits.array' => 'User traits must be an object/array.',
        ];
    }

    /**
     * Get the client ID from the request.
     */
    public function clientId(): string
    {
        $clientId = $this->input('client_id');

        return is_string($clientId) ? $clientId : '';
    }

    /**
     * Get the user traits from the request.
     *
     * @return array<string, mixed>
     */
    public function traits(): array
    {
        $traits = $this->input('traits', []);

        return is_array($traits) ? $traits : [];
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
