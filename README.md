# Elvesora Enrichment for PHP

A framework-independent PHP client for turning a business domain into a structured company profile with the [Elvesora Company Enrichment API](https://www.elvesora.com/products/data-enrichment).

## Requirements

- PHP 8.2 or later
- PHP `json` and `mbstring` extensions
- An Elvesora API key

Obtain and manage the key through the [Elvesora Enrichment documentation](https://www.elvesora.com/docs/enrichment). Keep it in server-side configuration; never expose it in browser or mobile client code.

## Installation

```console
composer require elvesora/enrichment-php
```

## Quick start

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Elvesora\Enrichment\Client;

$apiKey = getenv('ELVESORA_ENRICHMENT_API_KEY');

if (!is_string($apiKey) || trim($apiKey) === '') {
    throw new RuntimeException('ELVESORA_ENRICHMENT_API_KEY is not configured.');
}

$client = new Client($apiKey);

$result = $client->enrich(
    domain: 'acme.com',
    idempotencyKey: 'company-acme-20260803',
);

echo $result->data['company_name'] ?? $result->domain;
echo $result->credits->remaining;
```

The package does not load a `.env` file. If your application uses a dotenv library or framework environment loader, initialize it before reading the key and constructing the client.

`domain` must be non-empty and no longer than 255 characters. The optional idempotency key is trimmed, treats an empty string as absent, cannot exceed 255 characters, and cannot contain control characters. Invalid local input throws `InvalidArgumentException` before an HTTP request is sent.

## Results

Successful API responses return an immutable `EnrichmentResult`. It exposes readonly properties for:

- `success`, `resultType`, and `message`
- `data`, containing the structured company fields returned by the API
- `domain`, derived from the top-level response or company data when available
- `credits`, an immutable `CreditMetadata` object
- `statusCode`, `responseCode`, and `idempotencyStatus`

Use `$result->isEnriched()` to test for an `ENRICHED` result, `$result->wasReplayed()` to detect a cached idempotent replay, and `$result->toArray()` or `json_encode($result)` when the original response shape is more convenient.

`CreditMetadata` exposes `limit`, `used`, `remaining`, `consumedByRequest`, `periodStartedAt`, and `periodEndsAt`, plus `toArray()` and JSON serialization.

## Non-chargeable business responses

The API uses HTTP 400 when the domain was understood but no chargeable enrichment was produced. Current result types include `NOT_FOUND`, `FREE_EMAIL_PROVIDER`, `DISPOSABLE`, and `INVALID_DOMAIN`. These responses throw `ApiException` because they are non-success HTTP responses, while preserving `resultType`, `responseBody`, and any returned `credits` metadata:

```php
use Elvesora\Enrichment\Exception\ApiException;

try {
    $result = $client->enrich('example.com');
} catch (ApiException $exception) {
    if ($exception->statusCode !== 400) {
        throw $exception;
    }

    echo $exception->resultType;
    echo $exception->credits?->consumedByRequest; // 0 when metadata is present
}
```

## Errors

```php
use Elvesora\Enrichment\Exception\AuthenticationException;
use Elvesora\Enrichment\Exception\EnrichmentException;
use Elvesora\Enrichment\Exception\IdempotencyConflictException;
use Elvesora\Enrichment\Exception\UsageLimitException;
use Elvesora\Enrichment\Exception\ValidationException;

try {
    $result = $client->enrich('acme.com', 'company-acme-20260803');
} catch (InvalidArgumentException $exception) {
    // Invalid local configuration or input; no request was sent.
} catch (AuthenticationException $exception) {
    // The API rejected an invalid or revoked API key.
} catch (ValidationException $exception) {
    $errors = $exception->errors();
} catch (UsageLimitException $exception) {
    $limit = $exception->limit();
    $remaining = $exception->remaining();
} catch (IdempotencyConflictException $exception) {
    // The same key was previously used for another domain.
} catch (EnrichmentException $exception) {
    if ($exception->retryable) {
        $retryAfter = $exception->retryAfter;
    }
}
```

### Exception reference

| Exception | When it is thrown |
| --- | --- |
| `InvalidArgumentException` | Empty or invalid local configuration, domain, idempotency key, timeout, or base URL; no HTTP request is sent. |
| `AuthenticationException` | The response has result type `UNAUTHORIZED`, or is HTTP 401 without a result type. |
| `ValidationException` | The response has result type `VALIDATION_ERROR`, or is HTTP 422 without a result type. Use `errors()` for field-level validation messages. |
| `UsageLimitException` | The response has result type `LIMIT_EXCEEDED`, or is HTTP 429 without a result type. Use `limit()` and `remaining()` for credit metadata. |
| `IdempotencyConflictException` | The response has result type `IDEMPOTENCY_KEY_CONFLICT`, or is HTTP 409 without a result type. |
| `ApiException` | The API returns another non-success response, including HTTP 400 business outcomes and upstream failures. |
| `InvalidResponseException` | The API response is malformed JSON, is not a JSON object, has invalid headers, or violates the expected result or credit contract. |
| `TransportException` | The HTTP client cannot complete the request because of a connection, DNS, TLS, timeout, or similar transport failure. |

All SDK exceptions extend `EnrichmentException`. It exposes readonly `statusCode`, `resultType`, `retryable`, `retryAfter`, `responseBody`, `credits`, and `idempotencyStatus` properties. Credit metadata is optional on exceptions because authentication and request-validation responses may not include it. API keys are never added to exception messages.

Exception selection is based on `result_type` before HTTP status. This preserves upstream failures correctly when the API returns `UPSTREAM_ERROR` with the upstream status code.

## Idempotency and credits

Only an `ENRICHED` result consumes one credit. HTTP 400 business outcomes, request validation failures, authentication failures, credit-limit rejections, idempotency conflicts, and server or upstream errors do not consume a credit.

Pass an idempotency key when a request may be retried. Responses are retained for 24 hours. Reusing the same key with the same domain returns the retained response without consuming another credit and sets `Idempotency-Status: replayed`; reusing it with a different domain throws `IdempotencyConflictException` and sets its `idempotencyStatus` to `conflict` when the header is present.

The client does not retry automatically. Automatic retries can create duplicate credit consumption when no idempotency key is supplied. Decide whether to retry from the exception's `retryable` and `retryAfter` properties, and reuse the original idempotency key for the same domain.

## Configuration

```php
$client = new Client(
    apiKey: $apiKey,
    baseUrl: 'https://enrichment.elvesora.com/api/v1',
    timeout: 130,
    connectTimeout: 10,
    userAgent: 'MyApplication/1.0',
);
```

The default endpoint is `https://enrichment.elvesora.com/api/v1/enrichment/company`. HTTP and HTTPS custom base URLs are accepted so local and test environments can use the same client.

Timeout values are seconds and must be greater than zero. `connectTimeout` is capped internally at the total `timeout`. A custom base URL must be an absolute HTTP or HTTPS URL with a host and cannot contain embedded credentials, a query string, or a fragment. The user agent must be a non-empty string.

You may pass a custom Guzzle client as the second constructor argument. This supports shared handlers, proxies, observability middleware, and deterministic tests.

## Development

```console
composer install
composer check
```

The test suite uses mocked HTTP responses and sends no requests to the live Elvesora API.

## License

MIT. See [LICENSE](LICENSE).
