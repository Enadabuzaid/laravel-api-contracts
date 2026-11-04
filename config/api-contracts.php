<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Error Codes
    |--------------------------------------------------------------------------
    |
    | Machine-readable codes used by ApiExceptionRenderer for exceptions the
    | framework throws before a controller can build a specific response
    | (e.g. failed validation, missing/invalid auth, unknown routes).
    | Override per-service to match that service's own error contract.
    */
    'error' => [
        'validation_code' => 'VALIDATION_ERROR',
        'unauthenticated_code' => 'UNAUTHORIZED',
        'unauthorized_code' => 'FORBIDDEN',
        'not_found_code' => 'NOT_FOUND',
        'method_not_allowed_code' => 'METHOD_NOT_ALLOWED',
        'rate_limited_code' => 'RATE_LIMITED',
        'server_error_code' => 'SERVER_ERROR',
    ],

    /*
    | Idempotency: how long a stored response is replayed (seconds).
    */
    'idempotency' => [
        'ttl' => 86400,
    ],

    /*
    | Webhooks: max accepted clock skew for signed deliveries (seconds).
    */
    'webhooks' => [
        'tolerance' => 300,
    ],

    /*
    | Outbox (SERVICE_STANDARD §6). Subscriptions are webhook targets:
    | ['url' => ..., 'secret' => ..., 'events' => ['loyalty.*']] ('*' = all).
    | Backoff is base * 2^(attempt-1) seconds, capped at backoff_max.
    */
    'outbox' => [
        'subscriptions' => [],
        'max_attempts' => 8,
        'backoff_base' => 30,
        'backoff_max' => 3600,
        'batch' => 100,
        'timeout' => 10,
    ],

    /*
    | service.auth (SERVICE_STANDARD §5). Keys are validated against
    | identity-access-service; results are cached per key HASH for cache_ttl
    | seconds (never longer than the key's expires_at). cache_store null =
    | default store. Any failure to reach identity fails closed (503).
    */
    'identity' => [
        'base_url' => env('IDENTITY_BASE_URL'),
        'path' => '/api/v1/api-key/introspect',
        'timeout' => 3,
        'cache_ttl' => 60,
        'cache_store' => null,
    ],

    /*
    | tenant.context. The pattern must expose named groups `prefix` and `id`;
    | the prefix must equal the caller's X-Application.
    */
    'tenant' => [
        'header' => 'X-Tenant-Reference',
        'pattern' => '/^(?<prefix>[a-z][a-z0-9_-]*):tenant:(?<id>[A-Za-z0-9_-]+)$/',
    ],

];
