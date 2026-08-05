<?php

declare(strict_types=1);

namespace Elvesora\Enrichment\Exception;

use Elvesora\Enrichment\CreditMetadata;
use RuntimeException;
use Throwable;

class EnrichmentException extends RuntimeException
{
    /**
     * @param array<string, mixed> $responseBody
     */
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?string $resultType = null,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfter = null,
        public readonly array $responseBody = [],
        public readonly ?CreditMetadata $credits = null,
        public readonly ?string $idempotencyStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }
}
