<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form request for server-side page view tracking.
 *
 * Validates the POST /api/analytics/pageview endpoint.
 * Used by SPA/SSR apps that want server-side page view tracking.
 *
 * @see \ZeroBoiler\Analytics\Http\Controllers\AnalyticsEventController::pageview()
 *
 * @since 1.0.0
 */
final class PageViewRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get validation rules for page view tracking.
     *
     * @return array<string, string|array<string, string>>
     */
    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:500',
            'location' => 'sometimes|string|url|max:2048',
            'referrer' => 'sometimes|string|max:2048',
            'path' => 'sometimes|string|max:2048',
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
            'title' => 'page title',
            'location' => 'page URL',
            'referrer' => 'referrer URL',
            'path' => 'page path',
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
            'title.max' => 'The page title must not exceed 500 characters.',
            'location.url' => 'The page location must be a valid URL.',
            'location.max' => 'The page URL must not exceed 2048 characters.',
            'referrer.max' => 'The referrer URL must not exceed 2048 characters.',
            'path.max' => 'The page path must not exceed 2048 characters.',
        ];
    }

    /**
     * Get the page title from the request.
     */
    public function pageTitle(): ?string
    {
        $title = $this->input('title');

        return is_string($title) && $title !== '' ? $title : null;
    }

    /**
     * Get the page URL from the request.
     */
    public function pageLocation(): ?string
    {
        $location = $this->input('location');

        return is_string($location) && $location !== '' ? $location : null;
    }

    /**
     * Get the referrer URL from the request.
     */
    public function referrer(): ?string
    {
        $referrer = $this->input('referrer');

        return is_string($referrer) && $referrer !== '' ? $referrer : null;
    }

    /**
     * Get the page path from the request.
     */
    public function path(): ?string
    {
        $path = $this->input('path');

        return is_string($path) && $path !== '' ? $path : null;
    }
}
