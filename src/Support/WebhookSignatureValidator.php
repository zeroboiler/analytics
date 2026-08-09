<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Support;

/**
 * HMAC-SHA256 webhook signature validator.
 *
 * Validates incoming webhook payloads against an expected signature
 * using the configured secret. Supports both raw body and JSON payload
 * verification. Used by the WebhookTracker and for incoming webhook routes.
 *
 * @since 1.0.0
 */
final class WebhookSignatureValidator
{
    /**
     * Validate a webhook payload against its signature.
     *
     * @param  string  $payload  Raw request body
     * @param  string  $signature  HMAC-SHA256 signature from the X-ZB-Signature header
     * @param  string  $secret  Shared secret for HMAC computation
     * @return bool  True if signature matches
     */
    public static function valid(string $payload, string $signature, string $secret): bool
    {
        if ($signature === '' || $secret === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Compute the HMAC-SHA256 signature for a payload.
     *
     * Use this when sending outgoing webhook events.
     *
     * @param  string  $payload  Raw JSON body
     * @param  string  $secret  Shared secret
     * @return string  Hex-encoded HMAC-SHA256 signature
     */
    public static function sign(string $payload, string $secret): string
    {
        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * Extract and validate the signature from request headers.
     *
     * Looks for the X-ZB-Signature header (or X-Hub-Signature-256 for Meta compatibility).
     *
     * @param  string  $payload  Raw request body
     * @param  array<string, string|null>  $headers  Request headers
     * @param  string  $secret  Shared secret
     * @return bool  True if any known signature header validates
     */
    public static function validateFromHeaders(string $payload, array $headers, string $secret): bool
    {
        // Primary header: X-ZB-Signature
        $signature = $headers['x-zb-signature'] ?? $headers['X-ZB-Signature'] ?? null;

        if (is_string($signature) && $signature !== '' && self::valid($payload, $signature, $secret)) {
            return true;
        }

        // Compatibility: X-Hub-Signature-256 (Meta format: sha256=...)
        $metaSignature = $headers['x-hub-signature-256'] ?? $headers['X-Hub-Signature-256'] ?? null;

        if (is_string($metaSignature) && $metaSignature !== '') {
            $expected = 'sha256='.hash_hmac('sha256', $payload, $secret);

            return hash_equals($expected, $metaSignature);
        }

        return false;
    }
}
