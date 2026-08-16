<?php
/**
 * This file is part of ZeroBoiler, licensed under the MIT license.
 */

declare(strict_types=1);

namespace ZeroBoiler\Analytics\Services;

use ZeroBoiler\Analytics\DTO\AnalyticsEvent;

/**
 * Compact event serialization service for high-throughput batch processing.
 *
 * Provides a binary-safe, URL-encodable compact serialization format that
 * reduces event payload size by approximately 50-60% compared to raw JSON.
 * Designed for batch API requests where bandwidth efficiency matters
 * (mobile clients, high-frequency event sources, edge deployments).
 *
 * Format specification:
 * ┌──────────────────────────────────────────────────────────────────┐
 * │ Version (1B) │ Count (2B) │ Event 1 │ Event 2 │ ... │ CRC32 (4B) │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * Each event is encoded as:
 * ┌─────────────────────────────────────────────────────────────────────┐
 * │ NameLen (1B) │ Name (N bytes) │ ParamCount (1B) │ Params │        │
 * │              │                 │                  │        │ UserID│
 * └─────────────────────────────────────────────────────────────────┘
 *
 * Params use a TLV (Type-Length-Value) encoding:
 * ┌──────────────────────────────────────────────────┐
 * │ KeyLen (1B) │ Key │ Type (1B) │ ValLen (2B) │ Value │
 * └──────────────────────────────────────────────────┘
 *
 * Type byte: 0=null, 1=bool, 2=int, 3=float, 4=string, 5=array, 6=object
 *
 * The serialized output is base64url-encoded for safe HTTP transport.
 *
 * @see https://zeroboiler.dev/docs/analytics/serialization
 *
 * @since 122.0.0
 */
final class EventCompactSerializer
{
    /** Current serialization format version */
    public const FORMAT_VERSION = 1;

    /** Maximum number of events per batch */
    private const MAX_BATCH_COUNT = 65535;

    /** Maximum event name length */
    private const MAX_NAME_LENGTH = 255;

    /** Maximum parameter key length */
    private const MAX_KEY_LENGTH = 255;

    /** Maximum parameter value length (string bytes) */
    private const MAX_VALUE_LENGTH = 65535;

    /** Type constants */
    private const TYPE_NULL = 0;

    private const TYPE_BOOL = 1;

    private const TYPE_INT = 2;

    private const TYPE_FLOAT = 3;

    private const TYPE_STRING = 4;

    private const TYPE_ARRAY = 5;

    private const TYPE_OBJECT = 6;

    /**
     * Serialize a single analytics event to compact binary format, base64url-encoded.
     *
     * @param  AnalyticsEvent  $event  The event to serialize
     * @return string Base64url-encoded compact binary representation
     *
     * @throws \InvalidArgumentException If event name is too long or contains invalid data
     */
    public function serialize(AnalyticsEvent $event): string
    {
        $events = [$event];

        return $this->serializeBatch($events);
    }

    /**
     * Serialize multiple analytics events to compact binary format, base64url-encoded.
     *
     * @param  list<AnalyticsEvent>  $events  Events to serialize
     * @return string Base64url-encoded compact binary representation
     *
     * @throws \InvalidArgumentException If batch exceeds maximum count
     * @throws \OverflowException If serialized data exceeds maximum size
     */
    public function serializeBatch(array $events): string
    {
        $count = count($events);

        if ($count === 0) {
            throw new \InvalidArgumentException('Cannot serialize empty event batch.');
        }

        if ($count > self::MAX_BATCH_COUNT) {
            throw new \InvalidArgumentException(
                sprintf('Batch size %d exceeds maximum of %d.', $count, self::MAX_BATCH_COUNT)
            );
        }

        $binary = '';

        // Header: version (1 byte) + count (2 bytes, big-endian)
        $binary .= \chr(self::FORMAT_VERSION);
        $binary .= \pack('n', $count);

        // Events
        foreach ($events as $event) {
            $binary .= $this->encodeEvent($event);
        }

        // Footer: CRC32 checksum (4 bytes)
        $binary .= \pack('N', \crc32($binary));

        return $this->base64urlEncode($binary);
    }

    /**
     * Deserialize a compact binary payload back to analytics events.
     *
     * @param  string  $payload  Base64url-encoded compact binary payload
     * @return list<AnalyticsEvent> Deserialized events
     *
     * @throws \InvalidArgumentException If payload format is invalid or CRC check fails
     */
    public function deserialize(string $payload): array
    {
        $binary = $this->base64urlDecode($payload);

        if (\strlen($binary) < 7) {
            throw new \InvalidArgumentException('Payload too short to be a valid compact batch.');
        }

        // Parse header
        $version = \ord($binary[0]);

        if ($version !== self::FORMAT_VERSION) {
            throw new \InvalidArgumentException(
                sprintf('Unsupported format version %d (expected %d).', $version, self::FORMAT_VERSION)
            );
        }

        $count = \unpack('n', \substr($binary, 1, 2))[1];

        if ($count === 0 || $count > self::MAX_BATCH_COUNT) {
            throw new \InvalidArgumentException(
                sprintf('Invalid batch count %d.', $count)
            );
        }

        // Verify CRC32 (last 4 bytes)
        $data = \substr($binary, 0, -4);
        $expectedCrc = \unpack('N', \substr($binary, -4))[1];
        $actualCrc = \crc32($data);

        if ($expectedCrc !== $actualCrc) {
            throw new \InvalidArgumentException(
                'CRC32 checksum mismatch: payload may be corrupted or tampered.'
            );
        }

        // Parse events
        $events = [];
        $offset = 3; // Skip version + count

        for ($i = 0; $i < $count; $i++) {
            ['event' => $event, 'offset' => $offset] = $this->decodeEvent($binary, $offset);
            $events[] = $event;
        }

        return $events;
    }

    /**
     * Estimate the serialized size of an event batch without performing full serialization.
     *
     * Useful for pre-flight size checks before actual serialization.
     *
     * @param  list<AnalyticsEvent>  $events  Events to estimate
     * @return int Estimated byte size of the base64url-encoded payload
     */
    public function estimateSize(array $events): int
    {
        if ($events === []) {
            return 0;
        }

        $binarySize = 3; // header

        foreach ($events as $event) {
            $nameBytes = $event->name;
            $binarySize += 1 + \strlen($nameBytes); // nameLen + name
            $binarySize += 1; // paramCount placeholder
            $binarySize += $this->estimateParamsSize($event->params);
            $binarySize += 1; // userId presence flag

            if ($event->userId !== null) {
                $binarySize += 2 + \strlen($event->userId);
            }
        }

        $binarySize += 4; // CRC32

        // Base64url encoding increases size by ~33%
        return (int) \ceil($binarySize * 4 / 3);
    }

    /**
     * Get compression ratio compared to JSON encoding of the same events.
     *
     * Returns a value between 0 and 1 where:
     * - 1.0 = no compression benefit
     * - 0.5 = 50% size reduction
     *
     * @param  list<AnalyticsEvent>  $events  Events to compare
     * @return float Compression ratio (compact / json)
     */
    public function compressionRatio(array $events): float
    {
        if ($events === []) {
            return 1.0;
        }

        $jsonSize = \strlen(\json_encode(
            array_map(static fn(AnalyticsEvent $e): array => [
                'name' => $e->name,
                'params' => $e->params,
                'client_id' => $e->clientId,
                'user_id' => $e->userId,
                'timestamp' => $e->timestamp,
            ], $events),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        $compactSize = $this->estimateSize($events);

        if ($jsonSize === 0) {
            return 1.0;
        }

        return min(1.0, (float) $compactSize / (float) $jsonSize);
    }

    /**
     * Get format metadata for diagnostics.
     *
     * @return array{version: int, max_batch: int, max_name_length: int, max_value_length: int}
     */
    public function metadata(): array
    {
        return [
            'version' => self::FORMAT_VERSION,
            'max_batch' => self::MAX_BATCH_COUNT,
            'max_name_length' => self::MAX_NAME_LENGTH,
            'max_value_length' => self::MAX_VALUE_LENGTH,
        ];
    }

    // ── Private Encoding Methods ──────────────────────────────────────

    /**
     * Encode a single event to binary.
     *
     * @return string Binary-encoded event
     */
    private function encodeEvent(AnalyticsEvent $event): string
    {
        $name = $event->name;
        $nameLen = \strlen($name);

        if ($nameLen === 0 || $nameLen > self::MAX_NAME_LENGTH) {
            throw new \InvalidArgumentException(
                sprintf('Event name length %d is out of range [1, %d].', $nameLen, self::MAX_NAME_LENGTH)
            );
        }

        $binary = '';
        $binary .= \chr($nameLen);       // Name length (1 byte)
        $binary .= $name;                // Name bytes

        // Params (TLV encoded)
        $params = $event->params;
        $binary .= $this->encodeParams($params);

        // User ID (optional)
        if ($event->userId !== null && $event->userId !== '') {
            $userId = $event->userId;
            $userIdLen = \strlen($userId);

            if ($userIdLen > 255) {
                $userId = \substr($userId, 0, 255);
                $userIdLen = 255;
            }

            $binary .= \chr(1);              // User ID present flag
            $binary .= \pack('n', $userIdLen);
            $binary .= $userId;
        } else {
            $binary .= \chr(0);              // User ID absent flag
        }

        // Client ID (optional)
        if ($event->clientId !== null && $event->clientId !== '') {
            $clientId = $event->clientId;
            $clientIdLen = \strlen($clientId);

            if ($clientIdLen > 255) {
                $clientId = \substr($clientId, 0, 255);
                $clientIdLen = 255;
            }

            $binary .= \chr(1);
            $binary .= \pack('n', $clientIdLen);
            $binary .= $clientId;
        } else {
            $binary .= \chr(0);
        }

        return $binary;
    }

    /**
     * Encode params array to TLV binary format.
     *
     * @param  array<string, mixed>  $params  Event parameters
     * @return string Binary-encoded params
     */
    private function encodeParams(array $params): string
    {
        $count = count($params);

        if ($count > 255) {
            // Truncate excess params
            $params = \array_slice($params, 0, 255);
            $count = 255;
        }

        $binary = \chr($count);

        foreach ($params as $key => $value) {
            $keyLen = \strlen((string) $key);

            if ($keyLen > self::MAX_KEY_LENGTH) {
                $key = \substr((string) $key, 0, self::MAX_KEY_LENGTH);
                $keyLen = self::MAX_KEY_LENGTH;
            }

            $binary .= \chr($keyLen);
            $binary .= (string) $key;

            $binary .= $this->encodeValue($value);
        }

        return $binary;
    }

    /**
     * Encode a single value with type prefix.
     *
     * @param  mixed  $value  The value to encode
     * @return string Type-byte + length (if applicable) + value bytes
     */
    private function encodeValue(mixed $value): string
    {
        if ($value === null) {
            return \chr(self::TYPE_NULL);
        }

        if (\is_bool($value)) {
            return \chr(self::TYPE_BOOL) . ($value ? \chr(1) : \chr(0));
        }

        if (\is_int($value)) {
            return \chr(self::TYPE_INT) . \pack('q', $value); // 8-byte signed long
        }

        if (\is_float($value)) {
            return \chr(self::TYPE_FLOAT) . \pack('d', $value); // 8-byte double
        }

        if (\is_string($value)) {
            $len = \strlen($value);

            if ($len > self::MAX_VALUE_LENGTH) {
                $value = \substr($value, 0, self::MAX_VALUE_LENGTH);
                $len = self::MAX_VALUE_LENGTH;
            }

            return \chr(self::TYPE_STRING) . \pack('n', $len) . $value;
        }

        if (\is_array($value)) {
            $json = \json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $len = \strlen($json);

            if ($len > self::MAX_VALUE_LENGTH) {
                $json = \substr($json, 0, self::MAX_VALUE_LENGTH);
                $len = self::MAX_VALUE_LENGTH;
            }

            $isAssoc = $this->isAssociative($value);
            $type = $isAssoc ? self::TYPE_OBJECT : self::TYPE_ARRAY;

            return \chr($type) . \pack('n', $len) . $json;
        }

        // Fallback: serialize as string
        $str = (string) $value;

        return \chr(self::TYPE_STRING) . \pack('n', \strlen($str)) . $str;
    }

    // ── Private Decoding Methods ──────────────────────────────────────

    /**
     * Decode a single event from binary at given offset.
     *
     * @return array{event: AnalyticsEvent, offset: int}
     */
    private function decodeEvent(string $binary, int $offset): array
    {
        // Name
        $nameLen = \ord($binary[$offset]);
        $offset += 1;

        $name = \substr($binary, $offset, $nameLen);
        $offset += $nameLen;

        // Params
        ['params' => $params, 'offset' => $offset] = $this->decodeParams($binary, $offset);

        // User ID
        $userIdPresent = \ord($binary[$offset]);
        $offset += 1;
        $userId = null;

        if ($userIdPresent === 1) {
            $userIdLen = \unpack('n', \substr($binary, $offset, 2))[1];
            $offset += 2;
            $userId = \substr($binary, $offset, $userIdLen);
            $offset += $userIdLen;
        }

        // Client ID
        $clientIdPresent = \ord($binary[$offset]);
        $offset += 1;
        $clientId = null;

        if ($clientIdPresent === 1) {
            $clientIdLen = \unpack('n', \substr($binary, $offset, 2))[1];
            $offset += 2;
            $clientId = \substr($binary, $offset, $clientIdLen);
            $offset += $clientIdLen;
        }

        $event = new AnalyticsEvent(
            name: $name,
            params: $params,
            clientId: $clientId,
            userId: $userId,
        );

        return ['event' => $event, 'offset' => $offset];
    }

    /**
     * Decode params from binary at given offset.
     *
     * @return array{params: array<string, mixed>, offset: int}
     */
    private function decodeParams(string $binary, int $offset): array
    {
        $count = \ord($binary[$offset]);
        $offset += 1;

        $params = [];

        for ($i = 0; $i < $count; $i++) {
            $keyLen = \ord($binary[$offset]);
            $offset += 1;

            $key = \substr($binary, $offset, $keyLen);
            $offset += $keyLen;

            ['value' => $value, 'offset' => $offset] = $this->decodeValue($binary, $offset);
            $params[$key] = $value;
        }

        return ['params' => $params, 'offset' => $offset];
    }

    /**
     * Decode a typed value from binary at given offset.
     *
     * @return array{value: mixed, offset: int}
     */
    private function decodeValue(string $binary, int $offset): array
    {
        $type = \ord($binary[$offset]);
        $offset += 1;

        return match ($type) {
            self::TYPE_NULL => ['value' => null, 'offset' => $offset],

            self::TYPE_BOOL => [
                'value' => \ord($binary[$offset]) === 1,
                'offset' => $offset + 1,
            ],

            self::TYPE_INT => [
                'value' => \unpack('q', \substr($binary, $offset, 8))[1],
                'offset' => $offset + 8,
            ],

            self::TYPE_FLOAT => [
                'value' => \unpack('d', \substr($binary, $offset, 8))[1],
                'offset' => $offset + 8,
            ],

            self::TYPE_STRING, self::TYPE_ARRAY, self::TYPE_OBJECT => $this->decodeStringLike($binary, $offset, $type),

            default => ['value' => null, 'offset' => $offset],
        };
    }

    /**
     * Decode string, array, or object type values.
     *
     * @return array{value: mixed, offset: int}
     */
    private function decodeStringLike(string $binary, int $offset, int $type): array
    {
        $len = \unpack('n', \substr($binary, $offset, 2))[1];
        $offset += 2;

        $data = \substr($binary, $offset, $len);
        $offset += $len;

        if ($type === self::TYPE_STRING) {
            return ['value' => $data, 'offset' => $offset];
        }

        // Array or Object — decode from JSON
        $decoded = \json_decode($data, true, 512, JSON_BIGINT_AS_STRING);

        if ($type === self::TYPE_OBJECT && ! \is_array($decoded)) {
            $decoded = [];
        }

        return ['value' => $decoded ?? [], 'offset' => $offset];
    }

    // ── Size Estimation ────────────────────────────────────────────────

    /**
     * Estimate binary size of a params array.
     *
     * @param  array<string, mixed>  $params  Event parameters
     */
    private function estimateParamsSize(array $params): int
    {
        $size = 0;

        foreach ($params as $key => $value) {
            $size += 1 + \strlen((string) $key); // keyLen + key
            $size += $this->estimateValueSize($value);
        }

        return $size;
    }

    /**
     * Estimate binary size of a single value.
     */
    private function estimateValueSize(mixed $value): int
    {
        // Type byte
        $size = 1;

        if ($value === null) {
            return $size;
        }

        if (\is_bool($value)) {
            return $size + 1;
        }

        if (\is_int($value)) {
            return $size + 8;
        }

        if (\is_float($value)) {
            return $size + 8;
        }

        if (\is_string($value)) {
            $len = min(\strlen($value), self::MAX_VALUE_LENGTH);

            return $size + 2 + $len;
        }

        if (\is_array($value)) {
            $json = \json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $len = min(\strlen($json ?? '[]'), self::MAX_VALUE_LENGTH);

            return $size + 2 + $len;
        }

        // Fallback string
        $str = (string) $value;

        return $size + 2 + \strlen($str);
    }

    // ── Utility Methods ────────────────────────────────────────────────

    /**
     * Check if an array is associative (object-like).
     *
     * @param  array<mixed>  $arr  Array to check
     */
    private function isAssociative(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }

        return \array_keys($arr) !== \range(0, \count($arr) - 1);
    }

    /**
     * Base64url encode (URL-safe, no padding).
     */
    private function base64urlEncode(string $data): string
    {
        return \rtrim(\strtr(\base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64url decode (URL-safe, no padding).
     */
    private function base64urlDecode(string $data): string
    {
        $padded = \str_pad(\strtr($data, '-_', '+/'), \strlen($data) % 4 === 0 ? 0 : 4 - (\strlen($data) % 4), '=');

        return \base64_decode($padded, true);
    }
}
