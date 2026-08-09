<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for batch event tracking.
 *
 * Validates the POST /api/analytics/batch endpoint.
 * Accepts up to 25 events in a single request for efficient bulk tracking.
 *
 * @see \ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::batch()
 *
 * @since 1.0.0
 */
final class BatchEventRequest extends FormRequest
{
    /**
     * Maximum number of events allowed in a single batch.
     */
    private const MAX_BATCH_SIZE = 25;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get validation rules for batch event tracking.
     *
     * @return array<string, string|array<string, string>>
     */
    public function rules(): array
    {
        return [
            'events' => 'required|array|max:'.self::MAX_BATCH_SIZE,
            'events.*.name' => 'required|string|max:100',
            'events.*.params' => 'array',
            'events.*.params.*' => 'mixed',
            'events.*.client_id' => 'sometimes|string|max:64',
            'events.*.timestamp' => 'sometimes|date|before:now',
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
            'events' => 'events array',
            'events.*.name' => 'event name',
            'events.*.params' => 'event parameters',
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
            'events.required' => 'The events array is required.',
            'events.max' => 'A batch cannot contain more than '.self::MAX_BATCH_SIZE.' events.',
            'events.*.name.required' => 'Each event must have a name.',
            'events.*.name.max' => 'Event names must not exceed 100 characters.',
            'events.*.params.array' => 'Event parameters must be an object/array.',
        ];
    }

    /**
     * Get the validated events from the request.
     *
     * Each event is guaranteed to have at least a 'name' key.
     * Additional keys (params, client_id, timestamp) are optional.
     *
     * @return array<int, array{name: string, params: array<string, mixed>, client_id?: string, timestamp?: string}>
     */
    public function events(): array
    {
        $raw = $this->input('events', []);

        if (! is_array($raw)) {
            return [];
        }

        return array_map(static function (mixed $event): array {
            if (! is_array($event)) {
                return ['name' => '', 'params' => []];
            }

            $params = $event['params'] ?? [];
            $params = is_array($params) ? $params : [];

            return [
                'name' => is_string($event['name'] ?? '') ? $event['name'] : '',
                'params' => $params,
                'client_id' => is_string($event['client_id'] ?? null) ? $event['client_id'] : null,
                'timestamp' => is_string($event['timestamp'] ?? null) ? $event['timestamp'] : null,
            ];
        }, $raw);
    }

    /**
     * Get the batch size.
     */
    public function batchSize(): int
    {
        return count($this->events());
    }
}
