<?php

namespace Enadstack\ApiContracts\Http\Responses;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

trait ApiResponses
{
    /**
     * A flat, keyless success payload — the caller's array IS the body, so
     * each service's own contracted field names stay exactly where its API
     * documents them (no invented outer wrapper key).
     */
    public function successResponse(array $data = [], int $status = 200): JsonResponse
    {
        return response()->json($data, $status);
    }

    public function createdResponse(array $data = []): JsonResponse
    {
        return $this->successResponse($data, 201);
    }

    public function noContentResponse(): Response
    {
        return response()->noContent();
    }

    public function messageResponse(string $message, int $status = 200): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function errorResponse(string $code, string $message, array $details = [], int $status = 400): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) $details,
            ],
        ], $status);
    }

    public function paginatedResponse(LengthAwarePaginator $paginator, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $paginator->items(),
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
        ], $status);
    }
}
