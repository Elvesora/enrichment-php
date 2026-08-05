<?php

declare(strict_types=1);

namespace Elvesora\Enrichment;

use Elvesora\Enrichment\Exception\InvalidResponseException;
use JsonSerializable;

final readonly class EnrichmentResult implements JsonSerializable
{
    public const ENRICHED = 'ENRICHED';

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $raw
     */
    private function __construct(
        public bool $success,
        public string $resultType,
        public string $message,
        public array $data,
        public ?string $domain,
        public CreditMetadata $credits,
        public int $statusCode,
        public ?int $responseCode,
        public ?string $idempotencyStatus,
        private array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload, int $statusCode, ?string $idempotencyStatus = null): self
    {
        $success = $payload['success'] ?? null;
        if (!is_bool($success)) {
            throw self::invalid('The response field "success" must be a boolean.', $statusCode, $payload, $idempotencyStatus);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw self::invalid('Only successful HTTP responses can be converted to an enrichment result.', $statusCode, $payload, $idempotencyStatus);
        }

        if (!$success) {
            throw self::invalid('A successful HTTP response must contain a successful enrichment result.', $statusCode, $payload, $idempotencyStatus);
        }

        $resultType = self::requiredString($payload, 'result_type', $statusCode, $idempotencyStatus);
        if ($resultType !== self::ENRICHED) {
            throw self::invalid('A successful enrichment response must use the ENRICHED result type.', $statusCode, $payload, $idempotencyStatus);
        }

        $message = self::requiredString($payload, 'message', $statusCode, $idempotencyStatus);
        $data = $payload['data'] ?? [];
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw self::invalid('The response field "data" must be a JSON object when present.', $statusCode, $payload, $idempotencyStatus);
        }

        /** @var array<string, mixed> $data */
        $credits = self::credits($payload, $statusCode, $idempotencyStatus);
        self::validateCreditAliases($payload, $credits, $statusCode, $idempotencyStatus);

        $responseCode = $payload['response_code'] ?? null;
        if ($responseCode !== null && !is_int($responseCode)) {
            throw self::invalid('The response field "response_code" must be an integer when present.', $statusCode, $payload, $idempotencyStatus);
        }

        $idempotencyStatus = self::normalizedIdempotencyStatus($idempotencyStatus, $statusCode, $payload);
        if ($idempotencyStatus === 'conflict') {
            throw self::invalid('An enrichment result cannot have a conflicting idempotency status.', $statusCode, $payload, $idempotencyStatus);
        }

        return new self(
            success: $success,
            resultType: $resultType,
            message: $message,
            data: $data,
            domain: self::domain($payload, $data),
            credits: $credits,
            statusCode: $statusCode,
            responseCode: $responseCode,
            idempotencyStatus: $idempotencyStatus,
            raw: $payload,
        );
    }

    public function isEnriched(): bool
    {
        return $this->success && $this->resultType === self::ENRICHED;
    }

    public function wasReplayed(): bool
    {
        return $this->idempotencyStatus === 'replayed';
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
     * @param array<string, mixed> $payload
     */
    private static function requiredString(array $payload, string $key, int $statusCode, ?string $idempotencyStatus): string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw self::invalid(sprintf('The response field "%s" must be a non-empty string.', $key), $statusCode, $payload, $idempotencyStatus);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function credits(array $payload, int $statusCode, ?string $idempotencyStatus): CreditMetadata
    {
        if (!array_key_exists('credits', $payload) || $payload['credits'] === null) {
            throw self::invalid('A successful enrichment response must contain credit metadata.', $statusCode, $payload, $idempotencyStatus);
        }

        $value = $payload['credits'];
        if (!is_array($value) || array_is_list($value)) {
            throw self::invalid('The response field "credits" must be a JSON object.', $statusCode, $payload, $idempotencyStatus);
        }

        /** @var array<string, mixed> $value */
        return CreditMetadata::fromArray($value, $statusCode, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function validateCreditAliases(
        array $payload,
        CreditMetadata $credits,
        int $statusCode,
        ?string $idempotencyStatus,
    ): void {
        foreach (['limit', 'remaining'] as $field) {
            if (!array_key_exists($field, $payload)) {
                throw self::invalid(sprintf('The response field "%s" is required.', $field), $statusCode, $payload, $idempotencyStatus);
            }

            $value = $payload[$field];
            if (!is_int($value) || $value < 0) {
                throw self::invalid(sprintf('The response field "%s" must be a non-negative integer.', $field), $statusCode, $payload, $idempotencyStatus);
            }

            if ($value !== $credits->{$field}) {
                throw self::invalid(sprintf('The response field "%s" must match credits.%s.', $field, $field), $statusCode, $payload, $idempotencyStatus);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $data
     */
    private static function domain(array $payload, array $data): ?string
    {
        foreach ([$payload['domain'] ?? null, $data['domain'] ?? null] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function normalizedIdempotencyStatus(?string $value, int $statusCode, array $payload): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = strtolower(trim($value));
        if (!in_array($value, ['replayed', 'conflict'], true)) {
            throw self::invalid('The Idempotency-Status header contains an unsupported value.', $statusCode, $payload, $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function invalid(
        string $message,
        int $statusCode,
        array $payload,
        ?string $idempotencyStatus,
    ): InvalidResponseException {
        return new InvalidResponseException(
            message: $message,
            statusCode: $statusCode,
            resultType: 'INVALID_RESPONSE',
            retryable: $statusCode >= 500,
            responseBody: $payload,
            idempotencyStatus: $idempotencyStatus,
        );
    }
}
