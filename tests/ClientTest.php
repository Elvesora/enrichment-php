<?php

declare(strict_types=1);

namespace Elvesora\Enrichment\Tests;

use ArrayObject;
use Elvesora\Enrichment\Client;
use Elvesora\Enrichment\EnrichmentResult;
use Elvesora\Enrichment\Exception\ApiException;
use Elvesora\Enrichment\Exception\AuthenticationException;
use Elvesora\Enrichment\Exception\IdempotencyConflictException;
use Elvesora\Enrichment\Exception\InvalidResponseException;
use Elvesora\Enrichment\Exception\TransportException;
use Elvesora\Enrichment\Exception\UsageLimitException;
use Elvesora\Enrichment\Exception\ValidationException;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class ClientTest extends TestCase
{
    public function test_it_sends_the_public_contract_and_maps_an_immutable_result(): void
    {
        $history = new HistoryContainer();
        $client = $this->client([$this->jsonResponse(200, $this->successResponse())], $history);

        $result = $client->enrich('  acme.com  ');

        self::assertTrue($result->success);
        self::assertTrue($result->isEnriched());
        self::assertSame(EnrichmentResult::ENRICHED, $result->resultType);
        self::assertSame('Acme Corporation', $result->data['company_name']);
        self::assertSame('acme.com', $result->domain);
        self::assertSame(100, $result->credits->limit);
        self::assertSame(6, $result->credits->used);
        self::assertSame(94, $result->credits->remaining);
        self::assertSame(1, $result->credits->consumedByRequest);
        self::assertSame('2026-06-01', $result->credits->periodStartedAt);
        self::assertSame('2026-07-01', $result->credits->periodEndsAt);
        self::assertSame($this->successResponse(), $result->toArray());
        self::assertSame(
            json_encode($this->successResponse(), JSON_THROW_ON_ERROR),
            json_encode($result, JSON_THROW_ON_ERROR),
        );

        self::assertCount(1, $history);
        $entry = $history->entry(0);
        $request = $entry['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://example.test/api/v1/enrichment/company', (string) $request->getUri());
        self::assertSame('Bearer secret-api-key', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('Elvesora-Enrichment-PHP/1.0', $request->getHeaderLine('User-Agent'));
        self::assertFalse($request->hasHeader('Idempotency-Key'));
        self::assertSame(
            ['domain' => 'acme.com'],
            json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR),
        );
        self::assertSame(130.0, $entry['options']['timeout']);
        self::assertSame(10.0, $entry['options']['connect_timeout']);
        self::assertFalse($entry['options']['http_errors']);
    }

    public function test_it_sends_an_idempotency_key_and_exposes_replay_metadata(): void
    {
        $history = new HistoryContainer();
        $payload = $this->successResponse([
            'credits' => $this->creditResponse(0),
        ]);
        $client = $this->client([
            $this->jsonResponse(200, $payload, ['Idempotency-Status' => 'replayed']),
        ], $history);

        $result = $client->enrich('acme.com', '  company-acme-001  ');

        self::assertTrue($result->wasReplayed());
        self::assertSame('replayed', $result->idempotencyStatus);
        self::assertSame(0, $result->credits->consumedByRequest);
        self::assertSame('company-acme-001', $history->entry(0)['request']->getHeaderLine('Idempotency-Key'));
    }

    public function test_http_400_business_outcomes_are_non_retryable_api_exceptions(): void
    {
        $history = new HistoryContainer();
        $resultTypes = ['NOT_FOUND', 'FREE_EMAIL_PROVIDER', 'DISPOSABLE', 'INVALID_DOMAIN', 'PENDING'];
        $responses = [];

        foreach ($resultTypes as $resultType) {
            $responses[] = $this->jsonResponse(400, $this->businessResponse($resultType));
        }

        $client = $this->client($responses, $history);

        foreach ($resultTypes as $resultType) {
            try {
                $client->enrich('example.com');
                self::fail('Expected an API exception for an HTTP 400 business response.');
            } catch (ApiException $exception) {
                self::assertSame(400, $exception->statusCode);
                self::assertSame($resultType, $exception->resultType);
                self::assertFalse($exception->retryable);
                self::assertNotNull($exception->credits);
                self::assertSame(0, $exception->credits->consumedByRequest);
                self::assertSame(94, $exception->credits->remaining);
            }
        }

        self::assertCount(count($resultTypes), $history);
    }

    public function test_it_maps_documented_error_result_types_to_typed_exceptions(): void
    {
        $history = new HistoryContainer();
        $client = $this->client([
            $this->jsonResponse(401, [
                'success' => false,
                'result_type' => 'UNAUTHORIZED',
                'message' => 'Unauthorized',
            ]),
            $this->jsonResponse(422, [
                'success' => false,
                'result_type' => 'VALIDATION_ERROR',
                'message' => 'Validation failed.',
                'errors' => ['domain' => ['The domain field is required.']],
            ]),
            $this->jsonResponse(429, [
                'success' => false,
                'result_type' => 'LIMIT_EXCEEDED',
                'message' => 'Request limit exceeded. Please upgrade your plan.',
                'credits' => $this->creditResponse(0, used: 100, remaining: 0),
                'remaining' => 0,
                'limit' => 100,
            ], ['Retry-After' => '120']),
            $this->jsonResponse(409, [
                'success' => false,
                'result_type' => 'IDEMPOTENCY_KEY_CONFLICT',
                'message' => 'This Idempotency-Key was already used with a different domain.',
                'credits' => $this->creditResponse(0),
                'remaining' => 94,
                'limit' => 100,
            ], ['Idempotency-Status' => 'conflict']),
        ], $history);

        try {
            $client->enrich('example.com');
            self::fail('Expected an authentication exception.');
        } catch (AuthenticationException $exception) {
            self::assertSame(401, $exception->statusCode);
            self::assertSame('UNAUTHORIZED', $exception->resultType);
            self::assertNull($exception->credits);
        }

        try {
            $client->enrich('example.com');
            self::fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            self::assertSame(['domain' => ['The domain field is required.']], $exception->errors());
            self::assertFalse($exception->retryable);
        }

        try {
            $client->enrich('example.com');
            self::fail('Expected a usage-limit exception.');
        } catch (UsageLimitException $exception) {
            self::assertSame(100, $exception->limit());
            self::assertSame(0, $exception->remaining());
            self::assertSame(120, $exception->retryAfter);
            self::assertSame(0, $exception->credits?->consumedByRequest);
        }

        try {
            $client->enrich('another.example', 'company-acme-001');
            self::fail('Expected an idempotency-conflict exception.');
        } catch (IdempotencyConflictException $exception) {
            self::assertSame(409, $exception->statusCode);
            self::assertSame('IDEMPOTENCY_KEY_CONFLICT', $exception->resultType);
            self::assertSame('conflict', $exception->idempotencyStatus);
            self::assertSame(0, $exception->credits?->consumedByRequest);
        }

        self::assertCount(4, $history);
    }

    public function test_result_type_takes_priority_over_preserved_upstream_status(): void
    {
        $history = new HistoryContainer();
        $responses = [];

        foreach ([401, 422, 429] as $status) {
            $responses[] = $this->jsonResponse($status, [
                'success' => false,
                'result_type' => 'UPSTREAM_ERROR',
                'message' => 'Enrichment request failed',
                'response_code' => $status,
                'credits' => $this->creditResponse(0),
                'remaining' => 94,
                'limit' => 100,
            ]);
        }

        $client = $this->client($responses, $history);

        foreach ([401, 422, 429] as $status) {
            try {
                $client->enrich('example.com');
                self::fail('Expected a generic API exception for an upstream error.');
            } catch (ApiException $exception) {
                self::assertSame($status, $exception->statusCode);
                self::assertSame('UPSTREAM_ERROR', $exception->resultType);
            }
        }
    }

    public function test_status_is_used_as_a_fallback_when_result_type_is_absent(): void
    {
        $history = new HistoryContainer();
        $client = $this->client([
            $this->jsonResponse(401, ['message' => 'Unauthorized']),
        ], $history);

        $this->expectException(AuthenticationException::class);
        $client->enrich('example.com');
    }

    public function test_all_server_errors_are_retryable_and_are_not_retried_automatically(): void
    {
        $history = new HistoryContainer();
        $client = $this->client([
            $this->jsonResponse(599, [
                'success' => false,
                'result_type' => 'UPSTREAM_ERROR',
                'message' => 'Enrichment request failed',
                'response_code' => 599,
                'credits' => $this->creditResponse(0),
                'remaining' => 94,
                'limit' => 100,
            ]),
            $this->jsonResponse(200, $this->successResponse()),
        ], $history);

        try {
            $client->enrich('example.com', 'retry-safe-key');
            self::fail('Expected an API exception.');
        } catch (ApiException $exception) {
            self::assertTrue($exception->retryable);
            self::assertSame(599, $exception->statusCode);
        }

        self::assertCount(1, $history);
    }

    public function test_it_rejects_malformed_or_contract_invalid_responses(): void
    {
        $missingCredits = $this->successResponse();
        unset($missingCredits['credits']);

        $invalidDate = $this->successResponse([
            'credits' => array_replace($this->creditResponse(), ['period_ends_at' => '2026-02-30']),
        ]);

        $history = new HistoryContainer();
        $client = $this->client([
            new Response(200, ['Content-Type' => 'application/json'], '{not-json'),
            new Response(200, ['Content-Type' => 'application/json'], '["unexpected"]'),
            $this->jsonResponse(200, array_replace($this->successResponse(), ['success' => false])),
            $this->jsonResponse(200, array_replace($this->successResponse(), ['data' => ['unexpected']])),
            $this->jsonResponse(200, $missingCredits),
            $this->jsonResponse(200, array_replace($this->successResponse(), ['remaining' => 93])),
            $this->jsonResponse(200, $invalidDate),
            $this->jsonResponse(200, $this->successResponse(), ['Idempotency-Status' => 'unknown']),
        ], $history);

        for ($attempt = 0; $attempt < 8; $attempt++) {
            try {
                $client->enrich('example.com');
                self::fail('Expected an invalid-response exception.');
            } catch (InvalidResponseException $exception) {
                self::assertSame('INVALID_RESPONSE', $exception->resultType);
                self::assertSame(200, $exception->statusCode);
            }
        }

        self::assertCount(8, $history);
    }

    public function test_it_wraps_transport_failures_without_exposing_the_api_key(): void
    {
        $history = new HistoryContainer();
        $request = new Request('POST', 'https://example.test/api/v1/enrichment/company', [
            'Authorization' => 'Bearer secret-api-key',
        ]);
        $client = $this->client([new ConnectException('Connection failed', $request)], $history);

        try {
            $client->enrich('example.com', 'transport-key');
            self::fail('Expected a transport exception.');
        } catch (TransportException $exception) {
            self::assertTrue($exception->retryable);
            self::assertNull($exception->statusCode);
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString('secret-api-key', $exception->getMessage());
        }

        self::assertCount(1, $history);
    }

    public function test_it_validates_configuration_before_sending_a_request(): void
    {
        foreach ([
            static fn () => new Client(''),
            static fn () => new Client('secret', baseUrl: 'relative/path'),
            static fn () => new Client('secret', baseUrl: 'ftp://example.test/api'),
            static fn () => new Client('secret', baseUrl: 'https://example.test/api?tenant=one'),
            static fn () => new Client('secret', baseUrl: 'https://example.test/api#fragment'),
            static fn () => new Client('secret', baseUrl: 'https://user:password@example.test/api'),
            static fn () => new Client('secret', timeout: 0),
            static fn () => new Client('secret', connectTimeout: 0),
            static fn () => new Client('secret', userAgent: '   '),
        ] as $operation) {
            try {
                $operation();
                self::fail('Expected invalid client configuration to fail.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $client = new Client(
            'secret',
            baseUrl: 'HTTPS://example.test/api/v1/',
            timeout: 2,
            connectTimeout: 5,
        );

        self::assertSame('HTTPS://example.test/api/v1', $client->baseUrl());
        self::assertSame(2.0, $client->timeout());
        self::assertSame(2.0, $client->connectTimeout());
        self::assertSame(Client::DEFAULT_BASE_URL, (new Client('secret'))->baseUrl());
    }

    public function test_it_rejects_header_unsafe_api_keys_without_exposing_them(): void
    {
        $apiKey = "safe-prefix\r\nsecret-suffix";
        $parameter = new \ReflectionParameter([Client::class, '__construct'], 'apiKey');

        self::assertCount(1, $parameter->getAttributes(\SensitiveParameter::class));

        try {
            new Client($apiKey);
            self::fail('Expected a header-unsafe API key to fail.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringNotContainsString($apiKey, $exception->getMessage());
            self::assertStringNotContainsString('secret-suffix', $exception->getMessage());
        }
    }

    public function test_it_validates_domain_and_idempotency_input_before_sending_a_request(): void
    {
        $history = new HistoryContainer();
        $client = $this->client([$this->jsonResponse(200, $this->successResponse())], $history);
        $unicodeDomain = str_repeat('é', 255);

        $client->enrich($unicodeDomain, '   ');

        self::assertFalse($history->entry(0)['request']->hasHeader('Idempotency-Key'));
        self::assertSame(
            $unicodeDomain,
            json_decode((string) $history->entry(0)['request']->getBody(), true, 512, JSON_THROW_ON_ERROR)['domain'],
        );

        foreach ([
            static fn () => $client->enrich('   '),
            static fn () => $client->enrich(str_repeat('a', 256)),
            static fn () => $client->enrich('example.com', str_repeat('a', 256)),
            static fn () => $client->enrich('example.com', "valid\ninvalid"),
        ] as $operation) {
            try {
                $operation();
                self::fail('Expected invalid enrichment input to fail.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertCount(1, $history);
    }

    /**
     * @param list<Response|\Throwable> $responses
     */
    private function client(array $responses, HistoryContainer $history): Client
    {
        $mock = new MockHandler($responses);
        $handler = static function (RequestInterface $request, array $options) use ($mock, $history): PromiseInterface {
            $history[] = [
                'request' => $request,
                'options' => $options,
            ];

            return $mock($request, $options);
        };
        $stack = HandlerStack::create($handler);

        return new Client(
            apiKey: 'secret-api-key',
            httpClient: new HttpClient(['handler' => $stack]),
            baseUrl: 'https://example.test/api/v1/',
        );
    }

    /**
     * @param array<mixed>         $data
     * @param array<string, string> $headers
     */
    private function jsonResponse(int $status, array $data, array $headers = []): Response
    {
        return new Response(
            $status,
            array_merge(['Content-Type' => 'application/json'], $headers),
            json_encode($data, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function successResponse(array $overrides = []): array
    {
        return array_replace([
            'success' => true,
            'result_type' => 'ENRICHED',
            'message' => 'Company enrichment completed successfully',
            'data' => [
                'company_name' => 'Acme Corporation',
                'domain' => 'acme.com',
                'industry' => 'Software',
            ],
            'credits' => $this->creditResponse(),
            'remaining' => 94,
            'limit' => 100,
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function businessResponse(string $resultType): array
    {
        return [
            'success' => false,
            'result_type' => $resultType,
            'message' => 'No chargeable enrichment was produced.',
            'domain' => 'example.com',
            'data' => [],
            'credits' => $this->creditResponse(0),
            'remaining' => 94,
            'limit' => 100,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function creditResponse(int $consumed = 1, int $used = 6, int $remaining = 94): array
    {
        return [
            'limit' => 100,
            'used' => $used,
            'remaining' => $remaining,
            'consumed_by_request' => $consumed,
            'period_started_at' => '2026-06-01',
            'period_ends_at' => '2026-07-01',
        ];
    }
}

/**
 * @extends ArrayObject<int, array{request: RequestInterface, options: array<mixed>}>
 */
final class HistoryContainer extends ArrayObject
{
    /**
     * @return array{request: RequestInterface, options: array<mixed>}
     */
    public function entry(int $index): array
    {
        if (!$this->offsetExists($index)) {
            throw new OutOfBoundsException(sprintf('History entry %d does not exist.', $index));
        }

        $entry = $this->offsetGet($index);
        if ($entry === null) {
            throw new OutOfBoundsException(sprintf('History entry %d is empty.', $index));
        }

        return $entry;
    }
}
