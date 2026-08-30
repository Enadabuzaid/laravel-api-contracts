<?php

namespace Enadstack\ApiContracts\Tests\Unit;

use Enadstack\ApiContracts\Http\Responses\ApiResponses;
use Enadstack\ApiContracts\Tests\TestCase;
use Illuminate\Pagination\LengthAwarePaginator;

class ApiResponsesTest extends TestCase
{
    private function subject(): object
    {
        return new class
        {
            use ApiResponses;
        };
    }

    public function test_success_response_returns_flat_payload_with_no_wrapper_key(): void
    {
        $response = $this->subject()->successResponse(['user' => ['email' => 'a@b.com']]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['user' => ['email' => 'a@b.com']], $response->getData(true));
    }

    public function test_created_response_returns_201(): void
    {
        $response = $this->subject()->createdResponse(['id' => 1]);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(['id' => 1], $response->getData(true));
    }

    public function test_no_content_response_returns_204_with_empty_body(): void
    {
        $response = $this->subject()->noContentResponse();

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
    }

    public function test_message_response_returns_bare_message_key(): void
    {
        $response = $this->subject()->messageResponse('Logged out successfully');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['message' => 'Logged out successfully'], $response->getData(true));
    }

    public function test_error_response_matches_the_error_envelope_contract(): void
    {
        $response = $this->subject()->errorResponse('AUTHENTICATION_FAILED', 'Invalid credentials', [], 401);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([
            'error' => [
                'code' => 'AUTHENTICATION_FAILED',
                'message' => 'Invalid credentials',
                'details' => [],
            ],
        ], $response->getData(true));
    }

    public function test_error_response_encodes_empty_details_as_a_json_object_not_an_array(): void
    {
        $response = $this->subject()->errorResponse('SOME_ERROR', 'Something went wrong');

        $this->assertJsonStringEqualsJsonString(
            '{"error":{"code":"SOME_ERROR","message":"Something went wrong","details":{}}}',
            $response->getContent()
        );
    }

    public function test_error_response_preserves_non_empty_details_as_an_object(): void
    {
        $response = $this->subject()->errorResponse('VALIDATION_ERROR', 'Invalid input', ['email' => ['Required.']]);

        $this->assertJsonStringEqualsJsonString(
            '{"error":{"code":"VALIDATION_ERROR","message":"Invalid input","details":{"email":["Required."]}}}',
            $response->getContent()
        );
    }

    public function test_paginated_response_uses_data_links_meta_shape(): void
    {
        $paginator = new LengthAwarePaginator(
            items: ['a', 'b'],
            total: 5,
            perPage: 2,
            currentPage: 1,
            options: ['path' => 'https://example.test/api/v1/things'],
        );

        $response = $this->subject()->paginatedResponse($paginator);

        $this->assertSame(200, $response->getStatusCode());
        $payload = $response->getData(true);

        $this->assertSame(['a', 'b'], $payload['data']);
        $this->assertArrayHasKey('links', $payload);
        $this->assertArrayHasKey('first', $payload['links']);
        $this->assertArrayHasKey('last', $payload['links']);
        $this->assertArrayHasKey('prev', $payload['links']);
        $this->assertArrayHasKey('next', $payload['links']);
        $this->assertSame([
            'current_page' => 1,
            'from' => 1,
            'last_page' => 3,
            'per_page' => 2,
            'to' => 2,
            'total' => 5,
        ], $payload['meta']);
    }
}
