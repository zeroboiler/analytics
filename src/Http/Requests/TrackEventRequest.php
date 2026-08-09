<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for tracking a single analytics event.
 *
 * Validates the POST /api/analytics/events endpoint.
 * Uses Laravel's FormRequest for proper separation of validation logic.
 *
 * @see \ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::track()
 *
 * @since 1.0.0
 */
final class TrackEventRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Authorization is handled by the 'auth:sanctum' middleware on the route.
     * This method always returns true to avoid double-checking.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get validation rules for a single event tracking request.
     *
     * @return array<string, string|array<string, string>>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'params' => 'array',
            'params.*' => 'mixed',
            'client_id' => 'sometimes|string|max:64',
            'timestamp' => 'sometimes|date|before:now',
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
            'name' => 'event name',
            'params' => 'event parameters',
            'client_id' => 'client ID',
            'timestamp' => 'event timestamp',
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
            'name.required' => 'The event name is required.',
            'name.max' => 'The event name must not exceed 100 characters.',
            'name.string' => 'The event name must be a string.',
            'params.array' => 'Event parameters must be an object/array.',
            'client_id.max' => 'The client ID must not exceed 64 characters.',
        ];
    }

    /**
     * Get the event name from the request.
     */
    public function eventName(): string
    {
        $name = $this->input('name');

        return is_string($name) ? $name : '';
    }

    /**
     * Get the event parameters from the request.
     *
     * @return array<string, mixed>
     */
    public function eventParams(): array
    {
        $params = $this->input('params', []);

        return is_array($params) ? $params : [];
    }

    /**
     * Get the optional client ID from the request.
     */
    public function clientId(): ?string
    {
        $clientId = $this->input('client_id');

        return is_string($clientId) && $clientId !== '' ? $clientId : null;
    }

    /**
     * Get the optional timestamp from the request.
     */
    public function timestamp(): ?string
    {
        $timestamp = $this->input('timestamp');

        return is_string($timestamp) && $timestamp !== '' ? $timestamp : null;
    }
}
