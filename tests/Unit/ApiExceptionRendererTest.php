<?php

namespace Enadstack\ApiContracts\Tests\Unit;

use Enadstack\ApiContracts\Contracts\HasErrorCode;
use Enadstack\ApiContracts\Http\Exceptions\ApiExceptionRenderer;
use Enadstack\ApiContracts\Tests\TestCase;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ApiExceptionRendererTest extends TestCase
{
    public function test_validation_maps_the_validator_errors_into_details(): void
    {
        $exception = ValidationException::withMessages(['email' => ['The email field is required.']]);

        $response = ApiExceptionRenderer::validation($exception);

        $this->assertSame(422, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertSame('VALIDATION_ERROR', $payload['error']['code']);
        $this->assertSame(['email' => ['The email field is required.']], $payload['error']['details']);
    }

    public function test_unauthenticated_returns_401_with_the_configured_code(): void
    {
        $response = ApiExceptionRenderer::unauthenticated();

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('UNAUTHORIZED', $response->getData(true)['error']['code']);
    }

    public function test_unauthorized_returns_403(): void
    {
        $response = ApiExceptionRenderer::unauthorized();

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('FORBIDDEN', $response->getData(true)['error']['code']);
    }

    public function test_not_found_returns_404(): void
    {
        $response = ApiExceptionRenderer::notFound();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('NOT_FOUND', $response->getData(true)['error']['code']);
    }

    public function test_method_not_allowed_returns_405(): void
    {
        $response = ApiExceptionRenderer::methodNotAllowed();

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('METHOD_NOT_ALLOWED', $response->getData(true)['error']['code']);
    }

    public function test_rate_limited_returns_429(): void
    {
        $response = ApiExceptionRenderer::rateLimited();

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame('RATE_LIMITED', $response->getData(true)['error']['code']);
    }

    public function test_server_error_returns_500(): void
    {
        $response = ApiExceptionRenderer::serverError();

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('SERVER_ERROR', $response->getData(true)['error']['code']);
    }

    public function test_domain_uses_the_exceptions_own_error_code_status_and_details(): void
    {
        $exception = new class extends RuntimeException implements HasErrorCode
        {
            public function __construct()
            {
                parent::__construct('Session has expired.');
            }

            public function errorCode(): string
            {
                return 'SESSION_EXPIRED';
            }

            public function errorStatus(): int
            {
                return 401;
            }

            public function errorDetails(): array
            {
                return ['reason' => 'idle-timeout'];
            }
        };

        $response = ApiExceptionRenderer::domain($exception);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([
            'error' => [
                'code' => 'SESSION_EXPIRED',
                'message' => 'Session has expired.',
                'details' => ['reason' => 'idle-timeout'],
            ],
        ], $response->getData(true));
    }
}
