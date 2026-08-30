<?php

namespace Enadstack\ApiContracts\Http\Exceptions;

use Enadstack\ApiContracts\Contracts\HasErrorCode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Normalizes exceptions the framework throws before a controller method
 * ever runs (failed validation, missing/invalid auth, unknown routes, ...)
 * into the same {"error": {"code","message","details"}} envelope that
 * ApiResponses::errorResponse() produces, so nothing bypasses it.
 */
class ApiExceptionRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->render(fn (ValidationException $e, Request $request) => $request->expectsJson()
            ? static::validation($e)
            : null);

        $exceptions->render(fn (AuthenticationException $e, Request $request) => $request->expectsJson()
            ? static::unauthenticated()
            : null);

        $exceptions->render(fn (AuthorizationException $e, Request $request) => $request->expectsJson()
            ? static::unauthorized()
            : null);

        $exceptions->render(fn (ModelNotFoundException $e, Request $request) => $request->expectsJson()
            ? static::notFound()
            : null);

        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $request->expectsJson()
            ? static::notFound()
            : null);

        $exceptions->render(fn (MethodNotAllowedHttpException $e, Request $request) => $request->expectsJson()
            ? static::methodNotAllowed()
            : null);

        $exceptions->render(fn (TooManyRequestsHttpException $e, Request $request) => $request->expectsJson()
            ? static::rateLimited()
            : null);

        $exceptions->render(fn (HasErrorCode $e, Request $request) => $request->expectsJson()
            ? static::domain($e)
            : null);

        $exceptions->render(fn (Throwable $e, Request $request) => ($request->expectsJson() && ! config('app.debug'))
            ? static::serverError()
            : null);
    }

    public static function validation(ValidationException $exception): JsonResponse
    {
        return static::error(
            config('api-contracts.error.validation_code', 'VALIDATION_ERROR'),
            $exception->getMessage(),
            $exception->errors(),
            422,
        );
    }

    public static function unauthenticated(): JsonResponse
    {
        return static::error(
            config('api-contracts.error.unauthenticated_code', 'UNAUTHORIZED'),
            'Authentication required.',
            [],
            401,
        );
    }

    public static function unauthorized(): JsonResponse
    {
        return static::error(
            config('api-contracts.error.unauthorized_code', 'FORBIDDEN'),
            'This action is unauthorized.',
            [],
            403,
        );
    }

    public static function notFound(): JsonResponse
    {
        return static::error(
            config('api-contracts.error.not_found_code', 'NOT_FOUND'),
            'Resource not found.',
            [],
            404,
        );
    }

    public static function methodNotAllowed(): JsonResponse
    {
        return static::error(
            config('api-contracts.error.method_not_allowed_code', 'METHOD_NOT_ALLOWED'),
            'Method not allowed.',
            [],
            405,
        );
    }

    public static function rateLimited(): JsonResponse
    {
        return static::error(
            config('api-contracts.error.rate_limited_code', 'RATE_LIMITED'),
            'Too many requests.',
            [],
            429,
        );
    }

    public static function serverError(): JsonResponse
    {
        return static::error(
            config('api-contracts.error.server_error_code', 'SERVER_ERROR'),
            'Something went wrong.',
            [],
            500,
        );
    }

    public static function domain(HasErrorCode $exception): JsonResponse
    {
        return static::error(
            $exception->errorCode(),
            $exception->getMessage(),
            $exception->errorDetails(),
            $exception->errorStatus(),
        );
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private static function error(string $code, string $message, array $details, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) $details,
            ],
        ], $status);
    }
}
