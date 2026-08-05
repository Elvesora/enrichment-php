<?php

declare(strict_types=1);

namespace Elvesora\Enrichment;

use Elvesora\Enrichment\Exception\InvalidResponseException;
use JsonSerializable;

final readonly class CreditMetadata implements JsonSerializable
{
    /**
     * @param array<string, mixed> $raw
     */
    private function __construct(
        public int $limit,
        public int $used,
        public int $remaining,
        public int $consumedByRequest,
        public ?string $periodStartedAt,
        public ?string $periodEndsAt,
        private array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $responseBody
     */
    public static function fromArray(array $data, ?int $statusCode = null, array $responseBody = []): self
    {
        return new self(
            limit: self::nonNegativeInteger($data, 'limit', $statusCode, $responseBody),
            used: self::nonNegativeInteger($data, 'used', $statusCode, $responseBody),
            remaining: self::nonNegativeInteger($data, 'remaining', $statusCode, $responseBody),
            consumedByRequest: self::nonNegativeInteger($data, 'consumed_by_request', $statusCode, $responseBody),
            periodStartedAt: self::nullableDate($data, 'period_started_at', $statusCode, $responseBody),
            periodEndsAt: self::nullableDate($data, 'period_ends_at', $statusCode, $responseBody),
            raw: $data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $responseBody
     */
    private static function nonNegativeInteger(array $data, string $key, ?int $statusCode, array $responseBody): int
    {
        $value = $data[$key] ?? null;
        if (!is_int($value) || $value < 0) {
            throw self::invalid(sprintf('The response credit field "%s" must be a non-negative integer.', $key), $statusCode, $responseBody);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $responseBody
     */
    private static function nullableDate(array $data, string $key, ?int $statusCode, array $responseBody): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }

        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $matches) !== 1) {
            throw self::invalid(sprintf('The response credit field "%s" must be a date in YYYY-MM-DD format or null.', $key), $statusCode, $responseBody);
        }

        if (!checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            throw self::invalid(sprintf('The response credit field "%s" must contain a valid calendar date.', $key), $statusCode, $responseBody);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $responseBody
     */
    private static function invalid(string $message, ?int $statusCode, array $responseBody): InvalidResponseException
    {
        return new InvalidResponseException(
            message: $message,
            statusCode: $statusCode,
            resultType: 'INVALID_RESPONSE',
            retryable: $statusCode !== null && $statusCode >= 500,
            responseBody: $responseBody,
        );
    }
}
