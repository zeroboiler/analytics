<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Http\Requests;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Http\FormRequest;
use ZeroBoiler\Analytics\Events\EventCatalog;

/**
 * Form request for tracking a single analytics event.
 *
 * Validates the POST /api/analytics/events endpoint.
 * Uses Laravel's FormRequest for proper separation of validation logic.
 *
 * In strict validation mode (config: zeroboiler.analytics.validation.strict),
 * event names are validated against the event catalog. Only catalog-registered
 * event names are accepted. This prevents typos and unauthorized event injection.
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
            'priority' => 'sometimes|string|in:critical,normal,low,background',
        ];
    }

    /**
     * Configure the validator instance with catalog-aware validation.
     *
     * When strict validation is enabled, checks that the event name exists
     * in the event catalog. Adds a user-friendly error message suggesting
     * similar event names if the name is not found.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            $name = $this->input('name');

            if (! is_string($name) || $name === '') {
                return;
            }

            $maxLength = $this->getMaxEventNameLength();
            if (mb_strlen($name) > $maxLength) {
                $validator->errors()->add(
                    'name',
                    "The event name must not exceed {$maxLength} characters.",
                );
            }

            $whitelist = $this->getEventWhitelist();
            if (! empty($whitelist) && ! in_array($name, $whitelist, true)) {
                $validator->errors()->add(
                    'name',
                    "The event name '{$name}' is not in the allowed whitelist.",
                );

                return;
            }

            // Catalog validation in strict mode
            if ($this->isStrictValidation()) {
                if (! EventCatalog::has($name)) {
                    $suggestions = $this->suggestEventNames($name);
                    $suggestionText = ! empty($suggestions)
                        ? ' Did you mean: ' . implode(', ', $suggestions) . '?'
                        : '';

                    $validator->errors()->add(
                        'name',
                        "The event name '{$name}' is not registered in the event catalog.{$suggestionText}",
                    );
                }
            }
        });
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
            'priority' => 'event priority',
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
            'priority.in' => 'The priority must be one of: critical, normal, low, background.',
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

    /**
     * Get the event priority from the request.
     */
    public function priority(): ?string
    {
        $priority = $this->input('priority');

        if (! is_string($priority) || $priority === '') {
            return null;
        }

        $validPriorities = ['critical', 'normal', 'low', 'background'];

        return in_array($priority, $validPriorities, true) ? $priority : null;
    }

    /**
     * Check if strict event validation is enabled.
     *
     * Reads from the analytics validation config section.
     */
    private function isStrictValidation(): bool
    {
        try {
            /** @var ConfigRepository $config */
            $config = app(ConfigRepository::class);

            return (bool) $config->get('zeroboiler.analytics.validation.strict', false);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get the configured event name whitelist.
     *
     * @return list<string>
     */
    private function getEventWhitelist(): array
    {
        try {
            /** @var ConfigRepository $config */
            $config = app(ConfigRepository::class);
            $whitelist = $config->get('zeroboiler.analytics.validation.whitelist', []);

            return is_array($whitelist) ? $whitelist : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get the configured max event name length.
     */
    private function getMaxEventNameLength(): int
    {
        try {
            /** @var ConfigRepository $config */
            $config = app(ConfigRepository::class);

            return (int) $config->get('zeroboiler.analytics.validation.max_event_name_length', 100);
        } catch (\Throwable $e) {
            return 100;
        }
    }

    /**
     * Suggest similar event names from the catalog based on Levenshtein distance.
     *
     * Returns up to 3 closest matching event names sorted by similarity.
     *
     * @return list<string>
     */
    private function suggestEventNames(string $name): array
    {
        $catalogNames = EventCatalog::names();

        $searchParts = explode('_', strtolower($name));
        $scores = [];

        foreach ($catalogNames as $catalogName) {
            $catalogLower = strtolower($catalogName);

            // Exact substring match (high priority)
            if (str_contains($catalogLower, strtolower($name)) || str_contains(strtolower($name), $catalogLower)) {
                $scores[$catalogName] = 10;
                continue;
            }

            // Word overlap scoring
            $catalogParts = explode('_', $catalogLower);
            $overlap = count(array_intersect($searchParts, $catalogParts));
            $totalParts = count(array_unique(array_merge($searchParts, $catalogParts)));
            $jaccard = $totalParts > 0 ? $overlap / $totalParts : 0;

            // Levenshtein distance for edit distance
            $distance = levenshtein(strtolower($name), $catalogLower);
            $maxLen = max(mb_strlen($name), mb_strlen($catalogName), 1);
            $normalizedDistance = 1 - ($distance / $maxLen);

            $scores[$catalogName] = $jaccard * 5 + $normalizedDistance * 3;
        }

        arsort($scores);

        return array_slice(array_keys($scores), 0, 3);
    }
}
