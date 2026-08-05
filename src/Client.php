<?php

declare(strict_types=1);

namespace Elvesora\Enrichment;

use Elvesora\Enrichment\Contracts\EnrichmentClientInterface;
use Elvesora\Enrichment\Exception\ApiException;
use Elvesora\Enrichment\Exception\AuthenticationException;
use Elvesora\Enrichment\Exception\EnrichmentException;
use Elvesora\Enrichment\Exception\IdempotencyConflictException;
use Elvesora\Enrichment\Exception\InvalidResponseException;
use Elvesora\Enrichment\Exception\TransportException;
use Elvesora\Enrichment\Exception\UsageLimitException;
use Elvesora\Enrichment\Exception\ValidationException;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use InvalidArgumentException;
use JsonException;
use Psr\Http\Message\ResponseInterface;

final class Client implements EnrichmentClientInterface
{
    public const DEFAULT_BASE_URL = 'https://enrichment.elvesora.com/api/v1';

    public const ENRICHMENT_PATH = '/enrichment/company';

    private readonly ClientInterface $httpClient;

    private readonly string $apiKey;

    private readonly string $baseUrl;

    private readonly float $timeout;

    private readonly float $connectTimeout;

    private readonly string $userAgent;

    public function __construct(
        #[\SensitiveParameter] string $apiKey,
        ?ClientInterface $httpClient = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        float $timeout = 130.0,
        float $connectTimeout = 10.0,
        string $userAgent = 'Elvesora-Enrichment-PHP/1.0',
    ) {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new InvalidArgumentException('The Enrichment API key cannot be empty.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $apiKey) === 1) {
            throw new InvalidArgumentException('The Enrichment API key cannot contain control characters.');
        }

        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($baseUrl);
        if (
            filter_var($baseUrl, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || !is_string($parts['scheme'] ?? null)
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || !is_string($parts['host'] ?? null)
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new InvalidArgumentException('The Enrichment base URL must be a valid HTTP or HTTPS URL without credentials, a query string, or a fragment.');
        }

        if ($timeout <= 0) {
            throw new InvalidArgumentException('The request timeout must be greater than zero.');
        }

        if ($connectTimeout <= 0) {
            throw new InvalidArgumentException('The connection timeout must be greater than zero.');
        }

        $userAgent = trim($userAgent);
        if ($userAgent === '') {
            throw new InvalidArgumentException('The user agent cannot be empty.');
        }

        $this->apiKey = $apiKey;
        $this->httpClient = $httpClient ?? new HttpClient();
        $this->baseUrl = $baseUrl;
        $this->timeout = $timeout;
        $this->connectTimeout = min($connectTimeout, $timeout);
        $this->userAgent = $userAgent;
    }

    public function enrich(string $domain, ?string $idempotencyKey = null): EnrichmentResult
    {
        $domain = trim($domain);
        $this->assertLength($domain, 'domain', 255, false);

        if ($idempotencyKey !== null) {
            $idempotencyKey = trim($idempotencyKey);
            if ($idempotencyKey === '') {
                $idempotencyKey = null;
            } else {
                $this->assertLength($idempotencyKey, 'idempotency key', 255, true);
                if (preg_match('/[\x00-\x1F\x7F]/', $idempotencyKey) === 1) {
                    throw new InvalidArgumentException('The idempotency key cannot contain control characters.');
                }
            }
        }

        $response = $this->send($domain, $idempotencyKey);
        $statusCode = $response->getStatusCode();
        $idempotencyStatus = $this->idempotencyStatus($response);
        $data = $this->decode($response, $idempotencyStatus);

        if ($statusCode >= 200 && $statusCode < 300) {
            return EnrichmentResult::fromArray($data, $statusCode, $idempotencyStatus);
        }

        $this->throwForErrorResponse($response, $data, $idempotencyStatus);
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function timeout(): float
    {
        return $this->timeout;
    }

    public function connectTimeout(): float
    {
        return $this->connectTimeout;
    }

    private function send(string $domain, ?string $idempotencyKey): ResponseInterface
    {
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$this->apiKey,
            'Content-Type' => 'application/json',
            'User-Agent' => $this->userAgent,
        ];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        try {
            return $this->httpClient->request('POST', $this->baseUrl.self::ENRICHMENT_PATH, [
                'connect_timeout' => $this->connectTimeout,
                'headers' => $headers,
                'http_errors' => false,
                'json' => ['domain' => $domain],
                'timeout' => $this->timeout,
            ]);
        } catch (GuzzleException) {
            throw new TransportException(
                message: 'The Enrichment request could not be completed.',
                retryable: true,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response, ?string $idempotencyStatus): array
    {
        $contents = (string) $response->getBody();

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidResponseException(
                message: 'The Enrichment API returned malformed JSON.',
                statusCode: $response->getStatusCode(),
                resultType: 'INVALID_RESPONSE',
                retryable: $response->getStatusCode() >= 500,
                idempotencyStatus: $idempotencyStatus,
                previous: $exception,
            );
        }

        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidResponseException(
                message: 'The Enrichment API returned an unexpected response body.',
                statusCode: $response->getStatusCode(),
                resultType: 'INVALID_RESPONSE',
                retryable: $response->getStatusCode() >= 500,
                idempotencyStatus: $idempotencyStatus,
            );
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return never
     */
    private function throwForErrorResponse(
        ResponseInterface $response,
        array $data,
        ?string $idempotencyStatus,
    ): never {
        $statusCode = $response->getStatusCode();
        $resultType = $this->resultType($data);
        $message = is_string($data['message'] ?? null) && trim($data['message']) !== ''
            ? $data['message']
            : 'The Enrichment API returned an error.';
        $retryAfter = $this->retryAfter($response, $data);
        $credits = $this->credits($data, $statusCode, $idempotencyStatus);

        $exception = match ($resultType) {
            'UNAUTHORIZED' => AuthenticationException::class,
            'VALIDATION_ERROR' => ValidationException::class,
            'LIMIT_EXCEEDED' => UsageLimitException::class,
            'IDEMPOTENCY_KEY_CONFLICT' => IdempotencyConflictException::class,
            default => $resultType === null ? $this->exceptionForStatus($statusCode) : ApiException::class,
        };

        /** @var class-string<EnrichmentException> $exception */
        throw new $exception(
            message: $message,
            statusCode: $statusCode,
            resultType: $resultType,
            retryable: $statusCode === 408 || $statusCode >= 500,
            retryAfter: $retryAfter,
            responseBody: $data,
            credits: $credits,
            idempotencyStatus: $idempotencyStatus,
        );
    }

    /**
     * @return class-string<EnrichmentException>
     */
    private function exceptionForStatus(int $statusCode): string
    {
        return match ($statusCode) {
            401 => AuthenticationException::class,
            409 => IdempotencyConflictException::class,
            422 => ValidationException::class,
            429 => UsageLimitException::class,
            default => ApiException::class,
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resultType(array $data): ?string
    {
        $value = $data['result_type'] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function retryAfter(ResponseInterface $response, array $data): ?int
    {
        $retryAfter = $data['retry_after'] ?? $response->getHeaderLine('Retry-After');

        return is_numeric($retryAfter) ? max(1, (int) $retryAfter) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function credits(array $data, int $statusCode, ?string $idempotencyStatus): ?CreditMetadata
    {
        if (!array_key_exists('credits', $data) || $data['credits'] === null) {
            return null;
        }

        $value = $data['credits'];
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidResponseException(
                message: 'The response field "credits" must be a JSON object.',
                statusCode: $statusCode,
                resultType: 'INVALID_RESPONSE',
                retryable: $statusCode >= 500,
                responseBody: $data,
                idempotencyStatus: $idempotencyStatus,
            );
        }

        /** @var array<string, mixed> $value */
        return CreditMetadata::fromArray($value, $statusCode, $data);
    }

    private function idempotencyStatus(ResponseInterface $response): ?string
    {
        $value = trim($response->getHeaderLine('Idempotency-Status'));
        if ($value === '') {
            return null;
        }

        $value = strtolower($value);
        if (!in_array($value, ['replayed', 'conflict'], true)) {
            throw new InvalidResponseException(
                message: 'The Enrichment API returned an unsupported Idempotency-Status header.',
                statusCode: $response->getStatusCode(),
                resultType: 'INVALID_RESPONSE',
                retryable: $response->getStatusCode() >= 500,
                idempotencyStatus: $value,
            );
        }

        return $value;
    }

    private function assertLength(string $value, string $label, int $maximum, bool $allowEmpty): void
    {
        if (!$allowEmpty && $value === '') {
            throw new InvalidArgumentException(sprintf('The %s cannot be empty.', $label));
        }

        if (mb_strlen($value, 'UTF-8') > $maximum) {
            throw new InvalidArgumentException(sprintf('The %s cannot exceed %d characters.', $label, $maximum));
        }
    }
}
